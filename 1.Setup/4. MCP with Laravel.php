.env: 
============
# MiniMax API
MCP_PROVIDER=minimax
MINIMAX_API_KEY=****
MINIMAX_API_HOST=https://api.minimax.io   # global endpoint

# Model you want to use:
MCP_MODEL=MiniMax-M2.1

config/services.php: 
======================
  'minimax' => [
        'api_key' => env('MINIMAX_API_KEY', env('AI_API_KEY')),
        'host' => env('MINIMAX_API_HOST', 'https://api.minimax.io'),
        'model' => env('MCP_MODEL', env('AI_MODEL', 'MiniMax-M2.1')),
        'provider' => env('MCP_PROVIDER', 'minimax'),
    ],


web.php: 
=========

    //chatbot: _______________________________
    Route::get('/chat', [ChatbotController::class, 'index'])->name('chatbot.index');

    // Route::view('/chat', '');
    Route::post('/chat', [ChatbotController::class, 'chat']); 

Conroller: 
==========
<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\pragati\Order;
use App\Models\pragati\InsurancePackage;
use App\Models\pragati\Claim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ChatbotController extends Controller
{
    public function index()
    {
        return view('chatbot.ai-chatbot');
    }

    public function chat(Request $request)
    {
        $message = trim($request->message);
        $user = Auth::user();

        if (!$user) {
            return response()->json(['reply' => 'Please login first to file a claim or purchase a policy.']);
        }

        // Handle "yes" confirmation
        if (strtolower($message) === 'yes') {
            // Could add last action tracking here
        }

        // Check if user just sent a number (likely responding to order/claim question)
        if (preg_match('/^(\d+)$/', $message, $numMatch)) {
            $num = (int)$numMatch[1];
            
            $recentOrders = Order::where('user_id', $user->id)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
            
            $matchedOrder = $recentOrders->where('id', $num)->first();
            if ($matchedOrder) {
                $claimResult = $this->createClaim($user->id, $num, 0, 'Claim requested via chat');
                return response()->json(['reply' => $claimResult]);
            }
        }

        // FIRST: Check for claim creation command
        if (preg_match('/(file|create|submit|make)\s+(a\s+)?(claim|claims)/i', $message)) {
            if (preg_match('/(?:order|policy)\s*[#]?(\d+)/i', $message, $orderMatch)) {
                $orderId = (int)$orderMatch[1];
                
                $amount = 0;
                if (preg_match('/(\d+(?:\.\d+)?)\s*(tk|৳|taka|BDT)/i', $message, $amountMatch)) {
                    $amount = (float)$amountMatch[1];
                }
                
                $reason = 'Claim requested via chat';
                if (preg_match('/(?:for|because|due to|reason)\s+(.+)/i', $message, $reasonMatch)) {
                    $reason = trim($reasonMatch[1]);
                }
                
                $claimResult = $this->createClaim($user->id, $orderId, $amount, $reason);
                return response()->json(['reply' => $claimResult]);
            } else {
                $orders = Order::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->with('package')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get();
                
                if ($orders->count() > 0) {
                    $ordersList = $orders->map(function($order) {
                        return "Order #{$order->id}: {$order->package->name} (Policy: {$order->policy_number})";
                    })->implode("\n");
                    
                    return response()->json(['reply' => "কোন অর্ডারের জন্য ক্লেইম করতে চান? অর্ডার নম্বর বলুন:\n\n" . $ordersList . "\n\nউদাহরণ: \"Order #5\" বা শুধু \"5\""]);
                }
                return response()->json(['reply' => 'আপনার কোনো সক্রিয় পলিসি নেই। প্রথমে একটি প্যাকেজ কিনুন।']);
            }
        }

        // SECOND: Check for package number in message (for buying)
        if (preg_match('/(?:package|pkg|pack)(?:\s+number)?\s*(\d+)/i', $message, $matches)) {
            $packageId = (int)$matches[1];
            $orderResult = $this->createOrder($user->id, $packageId);
            return response()->json(['reply' => $orderResult]);
        }

        // THIRD: Check for direct buy/purchase with package number
        if (preg_match('/(?:buy|purchase|order|get)\s+(?:package\s+)?(\d+)/i', $message, $matches)) {
            $packageId = (int)$matches[1];
            $orderResult = $this->createOrder($user->id, $packageId);
            return response()->json(['reply' => $orderResult]);
        }

        // FOURTH: Check for "want to buy" or similar intent
        if (preg_match('/(want|need|like)\s+(to\s+)?(buy|purchase|get)/i', $message)) {
            $packages = InsurancePackage::where('is_active', true)->orderBy('id')->get();
            $packagesList = $packages->map(function($pkg) {
                return "Package {$pkg->id}: {$pkg->name} | Price: ৳{$pkg->price} | Coverage: ৳{$pkg->coverage_amount} | {$pkg->duration_months} months";
            })->implode("\n");
            
            return response()->json(['reply' => "Great! Here are our packages:\n\n" . $packagesList . "\n\nWhich package would you like? Just say the number (like \"1\" or \"Package 1\")."]);
        }

        // Build user context
        if ($user) {
            $orders = Order::where('user_id', $user->id)
                ->with('package')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $claims = Claim::where('user_id', $user->id)
                ->with(['package', 'order'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            $ordersInfo = $orders->map(function($order) {
                return "Order #{$order->id}: {$order->package->name} | Policy: {$order->policy_number} | Status: {$order->status}";
            })->implode("\n");

            $claimsInfo = $claims->map(function($claim) {
                return "Claim #{$claim->claim_number}: {$claim->package->name} | Amount: ৳{$claim->claim_amount} | Status: {$claim->status}";
            })->implode("\n");

            $userContext = "User ID: {$user->id}, Name: {$user->name}\n\nYOUR POLICIES:\n" . ($ordersInfo ?: "No policies yet.") . "\n\nYOUR CLAIMS:\n" . ($claimsInfo ?: "No claims yet.") . "\n\nUser asks in Bengali or English. Respond in same language.";
        } else {
            $userContext = "User is NOT logged in. Ask to login first.";
        }

        $packages = InsurancePackage::where('is_active', true)->orderBy('id')->get();
        $packagesInfo = $packages->map(function($pkg) {
            return "Package {$pkg->id}: {$pkg->name} | Price: ৳{$pkg->price} | Coverage: ৳{$pkg->coverage_amount} | {$pkg->duration_months} months";
        })->implode("\n");

        $allContext = $userContext . "\n\nAVAILABLE PACKAGES:\n" . $packagesInfo;

        $reply = $this->callMiniMax($message, $allContext);
        return response()->json(['reply' => $reply]);
    }

    private function createOrder($userId, $packageId)
    {
        DB::beginTransaction();
        try {
            $package = InsurancePackage::find($packageId);
            if (!$package) {
                return 'Package not found. Please select a valid package number (1, 2, or 3).';
            }

            $policyNumber = 'PL-' . date('Y') . '-' . strtoupper(uniqid());
            $startDate = now();
            $endDate = now()->addMonths($package->duration_months);

            $order = Order::create([
                'user_id' => $userId,
                'insurance_package_id' => $packageId,
                'policy_number' => $policyNumber,
                'status' => 'active',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);

            DB::commit();

            return "Policy Created Successfully!

Policy Number: {$policyNumber}
Package: {$package->name}
Coverage: ৳{$package->coverage_amount}
Price: ৳{$package->price}
Valid: {$startDate->format('d M Y')} - {$endDate->format('d M Y')}
Status: Active

Congratulations! Your policy is now active.";

        } catch (\Exception $e) {
            DB::rollBack();
            return 'Error: ' . $e->getMessage();
        }
    }

    private function createClaim($userId, $orderId, $amount, $reason)
    {
        DB::beginTransaction();
        try {
            $order = Order::where('id', $orderId)
                ->where('user_id', $userId)
                ->with('package')
                ->first();
            
            if (!$order) {
                return 'Order not found. Please provide a valid order number.';
            }

            if ($order->status !== 'active') {
                return 'This policy is not active. You can only file claims for active policies.';
            }

            if ($amount <= 0) {
                $amount = $order->package->coverage_amount;
            }

            $claimNumber = 'CLM-' . date('Y') . '-' . strtoupper(uniqid());

            $claim = Claim::create([
                'user_id' => $userId,
                'insurance_package_id' => $order->insurance_package_id,
                'order_id' => $order->id,
                'claim_number' => $claimNumber,
                'claim_amount' => $amount,
                'reason' => $reason,
                'status' => 'submitted',
            ]);

            DB::commit();

            return "Claim Filed Successfully!

Claim Number: {$claimNumber}
Policy: {$order->package->name}
Policy Number: {$order->policy_number}
Claim Amount: ৳{$amount}
Reason: {$reason}
Status: Submitted

Your claim has been submitted for review. We will contact you within 2-3 business days.";

        } catch (\Exception $e) {
            DB::rollBack();
            return 'Error: ' . $e->getMessage();
        }
    }

    private function callMiniMax($message, $userContext)
    {
        $apiKey = config('services.minimax.api_key');
        $host = config('services.minimax.host', 'https://api.minimax.io');
        $model = config('services.minimax.model', 'MiniMax-M2.1');
        
        if (empty($apiKey)) {
            return 'Error: MiniMax API key not configured.';
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($host . '/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => "You are Pragati Life Insurance Assistant. Be friendly and helpful. Give SHORT, DIRECT answers. Never show internal thinking or notes. Never explain what you are doing. Just give the answer. Speak naturally like a human. " . $userContext
                    ],
                    [
                        'role' => 'user',
                        'content' => $message
                    ]
                ],
                'temperature' => 0.3,
                'max_tokens' => 500
            ]);

            $status = $response->status();

            if ($status === 200) {
                $data = $response->json();
                if (isset($data['choices']) && count($data['choices']) > 0) {
                    $content = $data['choices'][0]['message']['content'];
                    return $this->cleanResponse($content);
                }
                return 'Error: Empty response.';
            }

            return 'Server temporarily unavailable. Please try again.';
            
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    private function cleanResponse($content)
    {
        // Remove all thinking blocks
        $content = str_replace(['<think>', ']', '[THINKING]', '[/THINKING]'], '', $content);
        
        // Remove lines starting with internal references or meta-comments
        $lines = explode("\n", $content);
        $cleanLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Skip lines that are internal notes or meta-comments about user intent
            if (preg_match('/^(The user just said|The user is|I should|I will|They have|They already|I need|Based on|The system|I understand|Since the user|Looking at|So I|If the user|I should respond|per my instructions|as per my)/i', $trimmed)) {
                continue;
            }
            // Skip if line contains any internal thinking phrases
            if (stripos($trimmed, 'The user just said') !== false || 
                stripos($trimmed, 'I should respond') !== false ||
                stripos($trimmed, 'as Pragati') !== false ||
                stripos($trimmed, 'The user is identified') !== false ||
                stripos($trimmed, 'Let me see') !== false || 
                stripos($trimmed, 'Let me check') !== false ||
                stripos($trimmed, 'I will respond') !== false ||
                stripos($trimmed, 'Since they said') !== false ||
                stripos($trimmed, 'They said') !== false ||
                stripos($trimmed, 'In English') !== false ||
                stripos($trimmed, 'in Bengali') !== false ||
                stripos($trimmed, 'in English') !== false ||
                stripos($trimmed, 'without a clear') !== false ||
                stripos($trimmed, 'quite ambiguous') !== false ||
                stripos($trimmed, 'naturally and offer') !== false ||
                stripos($trimmed, 'welcoming manner') !== false ||
                stripos($trimmed, 'identified as') !== false ||
                stripos($trimmed, 'friendly') !== false) {
                continue;
            }
            $cleanLines[] = $line;
        }
        $content = implode("\n", $cleanLines);
        
        // Clean up multiple newlines
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        
        return trim($content);
    }
}

; 
Chatbot View: 
---------------
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pragati Life AI Assistant</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    :root {
      /* --bg-gradient: linear-gradient(135deg, #4ab59a 0%, #0d5540 100%); */
      /* --bg-gradient: linear-gradient(135deg, #8aead2 0%, #0a8662 100%); */
      --bg-gradient: linear-gradient(135deg, #4ab59a 0%, #0d5540 100%);
      --glass-bg: rgba(255, 255, 255, 0.12);
      --glass-border: rgba(255, 255, 255, 0.2);
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg-gradient);
      min-height: 100vh;
      color: rgb(239, 255, 225);
      margin: 0;
      padding-bottom: 60px;
    }

    .glass-card {
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
      border-radius: 28px;
      box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.25);
    }

    .glass-button {
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(8px);
      border: 1px solid var(--glass-border);
      color: white;
      border-radius: 50px;
      padding: 10px 24px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 500;
    }

    .glass-button:hover {
      background: rgba(255, 255, 255, 0.2);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    }

    .chat-container {
      height: 800px; 
      display: flex;
      flex-direction: column;
      overflow: hidden;
      width: 100%;
    }

    .chat-header {
      padding: 20px;
      background: rgba(255, 255, 255, 0.95);
      border-bottom: 1px solid rgba(0,0,0,0.05);
      color: #1f2937;
    }

    .chat-messages {
      flex-grow: 1;
      overflow-y: auto;
      padding: 25px;
      background: rgba(255, 255, 255, 0.03);
    }

    .message {
      margin-bottom: 20px;
      max-width: 80%;
      padding: 16px 20px;
      border-radius: 20px;
      font-size: 0.95rem;
      line-height: 1.6;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .message-user {
      align-self: flex-end;
      background: #059669;
      color: rgb(255, 255, 255);
      border-bottom-right-radius: 4px;
      margin-left: auto;
    }

    .message-ai {
      align-self: flex-start;
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.2);
      color: rgb(0, 0, 0);
      border-bottom-left-radius: 4px;
    }

    .timestamp {
      font-size: 0.7rem;
      opacity: 0.6;
      display: block;
      margin-top: 6px;
    }

    .chat-input-area {
      padding: 20px;
      background: rgba(0, 0, 0, 0.15);
      border-top: 1px solid var(--glass-border);
    }

    .form-control-custom {
      background: rgba(255, 255, 255, 0.1) !important;
      border: 1px solid var(--glass-border) !important;
      color: white !important;
      border-radius: 35px !important;
      padding: 14px 25px !important;
      font-size: 1rem;
    }

    .form-control-custom:focus {
      background: rgba(255, 255, 255, 0.15) !important;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.3);
    }

    .action-card {
      transition: all 0.3s ease;
      cursor: pointer;
      border: 1px solid rgba(255,255,255,0.1);
    }

    .action-card:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: scale(1.02) translateX(5px);
      border-color: rgba(255,255,255,0.3);
    }

    .online-badge {
      font-size: 0.7rem;
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
      padding: 2px 8px;
      border-radius: 10px;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .online-dot {
      width: 6px;
      height: 6px;
      background: #10b981;
      border-radius: 50%;
      box-shadow: 0 0 8px #10b981;
    }

    .typing-indicator span {
      height: 7px;
      width: 7px;
      background: white;
      display: inline-block;
      border-radius: 50%;
      opacity: 0.4;
      animation: typing 1s infinite;
      margin: 0 2px;
    }

    @keyframes typing {
      0%, 100% { transform: translateY(0); }
      50% { transform: translateY(-6px); }
    }
  </style>
</head>
<body>

  <div class="container pb-5">
    <!-- Header -->
    <header class="glass-card mt-3 mb-4 p-3 d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center gap-3 ps-2">
       <div class="text-center gap-3 mb-1 ">
                <img class="bg-white " src="{{ asset('vms/logo/pragatiLogo.png') }}" style="height: 70px; width: 150px; border-radius:10px;" alt="UCB Bank Logo">
        </div>
        {{-- <div>
          <h5 class="mb-0 fw-bold">Pragati Life</h5>
          <small class="text-uppercase opacity-75" style="font-size: 0.65rem; letter-spacing: 1.5px; font-weight: 600;">Insurance PLC</small>
        </div> --}}
      </div>
      <div class="d-flex gap-3 pe-2 ">
        <a href="{{ route('dashboard') }}" class="glass-button text-black"><i class="fas fa-home text-black"></i>  Dashboard</a>
        {{-- <a href="#" class="glass-button d-none d-md-flex"><i class="fas fa-user-circle"></i> Login</a> --}}
      </div>
    </header>

    <!-- Main Content Grid -->
    <div class="row g-5 align-items-start">
      <!-- Left Column: Info & Actions -->
      <div class="col-lg-4">
        {{-- <div class="glass-card p-4 mb-4" style="border-left: 5px solid #10b981;">
          <h2 class="fw-bold mb-3 lh-base">Secure Your Future Today</h2>
          <p class="opacity-80 mb-4 fs-5">Empowering thousands of families in Bangladesh with trusted insurance solutions. Start a conversation to find your perfect plan.</p>
          <div class="d-flex gap-3">
            <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-20 py-2 px-3"><i class="fas fa-check-circle me-1 text-success"></i> Fast Claims</span>
            <span class="badge rounded-pill bg-white bg-opacity-10 border border-white border-opacity-20 py-2 px-3"><i class="fas fa-shield-alt me-1 text-success"></i> Trusted</span>
          </div>
        </div> --}}

        <div class="d-flex flex-column gap-3">
          <div class="glass-card p-4 action-card d-flex align-items-center gap-4" onclick="triggerAction('Calculate Premium')">
            <div class="bg-success bg-opacity-20 rounded-4 p-3 text-emerald-300">
              <i class="fas fa-calculator fs-4"></i>
            </div>
            <div>
              <h6 class="mb-1 fw-bold fs-5">Premium Calculator</h6>
              <small class="opacity-60">Check expected premiums for policies.</small>
            </div>
          </div>

          <div class="glass-card p-4 action-card d-flex align-items-center gap-4" onclick="triggerAction('Check Policy Status')">
            <div class="bg-success bg-opacity-20 rounded-4 p-3 text-emerald-300">
              <i class="fas fa-search fs-4"></i>
            </div>
            <div>
              <h6 class="mb-1 fw-bold fs-5">Policy Status</h6>
              <small class="opacity-60">View your active insurance details.</small>
            </div>
          </div>

          <div class="glass-card p-4 action-card d-flex align-items-center gap-4" onclick="triggerAction('How to file a claim')">
            <div class="bg-success bg-opacity-20 rounded-4 p-3 text-emerald-300">
              <i class="fas fa-file-invoice-dollar fs-4"></i>
            </div>
            <div>
              <h6 class="mb-1 fw-bold fs-5">File a Claim</h6>
              <small class="opacity-60">Submit and track insurance claims.</small>
            </div>
          </div>

          <div class="glass-card p-4 action-card d-flex align-items-center gap-4" onclick="triggerAction('Show Frequently Asked Questions')">
            <div class="bg-success bg-opacity-20 rounded-4 p-3 text-emerald-300">
              <i class="fas fa-question-circle fs-4"></i>
            </div>
            <div>
              <h6 class="mb-1 fw-bold fs-5">FAQ</h6>
              <small class="opacity-60">Frequently Asked Questions.</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Chatbot -->
      <div class="col-lg-8">
        <div class="glass-card chat-container shadow-lg">
          <!-- Chat Header -->
          <div class="chat-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
              <div class="position-relative">
                <div class="rounded-circle bg-success d-flex align-items-center justify-content-center shadow-sm" style="width: 48px; height: 48px;">
                  <i class="fas fa-robot text-white fs-4"></i>
                </div>
              </div>
              <div>
                <h6 class="mb-0 fw-bold text-dark">Pragati AI Assistant</h6>
                <div class="online-badge">
                  <span class="online-dot"></span> Online Now
                </div>
              </div>
            </div>
            <div class="d-flex gap-4 text-secondary opacity-75">
              <button class="btn btn-link p-0 text-dark" onclick="clearChat()" title="Clear History"><i class="fas fa-trash-alt"></i></button>
              <button class="btn btn-link p-0 text-dark"><i class="fas fa-phone-alt"></i></button>
              <button class="btn btn-link p-0 text-dark"><i class="fas fa-ellipsis-v"></i></button>
            </div>
          </div>

          <!-- Messages Area -->
          <div class="chat-messages d-flex flex-column" id="chatMessages">
          </div>

          <!-- Typing Indicator -->
          <div id="typingIndicator" class="px-4 py-2 d-none">
            <div class="message message-ai">
              <div class="typing-indicator">
                <span></span> <span></span> <span></span>
              </div>
            </div>
          </div>

          <!-- Input Area -->
          <div class="chat-input-area">
            <form id="chatForm" class="d-flex gap-3 align-items-center">
              <input type="text" id="userInput" class="form-control form-control-custom" placeholder="Type your message here..." autocomplete="off">
              <button type="submit" class="btn btn-success rounded-circle d-flex align-items-center justify-content-center shadow-lg" style="width: 54px; height: 54px; flex-shrink: 0;">
                <i class="fas fa-paper-plane fs-5"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const STORAGE_KEY = 'pragati_chat_history_v3';
    const chatMessagesEl = document.getElementById('chatMessages');
    const chatForm = document.getElementById('chatForm');
    const userInput = document.getElementById('userInput');
    const typingIndicator = document.getElementById('typingIndicator');

    let messages = [];

    // Initialize
    window.onload = () => {
      loadHistory();
      if (messages.length === 0) {
        addMessage('assistant', "Hello! I am Pragati Life's AI Assistant. I can help you with premium calculations, policy details, or claim processes. How can I help you today?");
      } else {
        renderMessages();
      }
    };

    function loadHistory() {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved) {
        try {
          messages = JSON.parse(saved);
        } catch (e) {
          console.error("Failed to load history", e);
        }
      }
    }

    function saveHistory() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(messages));
    }

    function renderMessages() {
      chatMessagesEl.innerHTML = '';
      messages.forEach(msg => {
        const div = document.createElement('div');
        div.className = `message ${msg.role === 'user' ? 'message-user' : 'message-ai'}`;
        div.innerHTML = `
          <div style="white-space: pre-wrap;">${msg.content}</div>
          <span class="timestamp">${new Date(msg.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</span>
        `;
        chatMessagesEl.appendChild(div);
      });
      chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
    }

    function addMessage(role, content) {
      messages.push({
        role,
        content,
        timestamp: new Date().toISOString()
      });
      renderMessages();
      saveHistory();
    }

    window.triggerAction = (text) => {
      handleUserSubmit(text);
    };

    async function handleUserSubmit(text) {
      if (!text.trim()) return;
      
      addMessage('user', text);
      userInput.value = '';
      
      typingIndicator.classList.remove('d-none');
      chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;

      try {
        const res = await fetch('/chat', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
          },
          body: JSON.stringify({ message: text })
        });

        const data = await res.json();
        typingIndicator.classList.add('d-none');
        
        if (data.reply) {
          addMessage('assistant', data.reply);
        } else {
          addMessage('assistant', "I'm having a little trouble connecting to my servers right now. Please try again in a moment.");
        }
      } catch (error) {
        console.error("Error:", error);
        typingIndicator.classList.add('d-none');
        addMessage('assistant', "I'm having a little trouble connecting to my servers right now. Please try again in a moment.");
      }
    }

    chatForm.addEventListener('submit', (e) => {
      e.preventDefault();
      handleUserSubmit(userInput.value);
    });

    window.clearChat = () => {
      if(confirm("Delete conversation history?")) {
        messages = [];
        localStorage.removeItem(STORAGE_KEY);
        addMessage('assistant', "History cleared. How can I help you starting fresh?");
      }
    };
  </script>
</body>
</html>
; 
