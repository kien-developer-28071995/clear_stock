<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\FeedbackMail;
use App\Services\App\ReviewPromptService;
use App\Support\ShopContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/** The feedback box (sent to the support address) and the result of asking for an App Store review. */
class FeedbackController extends Controller
{
    public function store(Request $request, ShopContext $context): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:2000'],
            // Where to answer; empty = no answer wanted.
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        Mail::to(config('shopify.support_email'))->queue(new FeedbackMail($context->shop(), trim($data['message']), $data['email'] ?? null));

        return response()->json(['data' => ['sent' => true]], 202);
    }

    /** What `shopify.reviews.request()` answered in the merchant's browser. */
    public function reviewPrompt(Request $request, ShopContext $context, ReviewPromptService $reviews): Response
    {
        $data = $request->validate(['code' => ['required', 'string', Rule::in(ReviewPromptService::CODES)]]);
        $reviews->record($context->shop(), $data['code']);

        return response()->noContent();
    }
}
