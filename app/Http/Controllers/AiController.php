<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class AiController extends Controller
{
    /**
     * Save AI settings (endpoint, API key, selected model) for a workspace.
     */
    public function saveSettings(Request $request, Workspace $workspace)
    {
        $this->authorize('update', $workspace);

        $validated = $request->validate([
            'ai_endpoint' => 'nullable|url|max:500',
            'ai_api_key'  => 'nullable|string|max:500',
            'ai_model'    => 'nullable|string|max:255',
        ]);

        $settings = $workspace->settings ?? [];

        $settings['ai_endpoint'] = $validated['ai_endpoint'] ?? null;
        $settings['ai_model']    = $validated['ai_model'] ?? null;

        // Encrypt the API key before storing
        if (! empty($validated['ai_api_key'])) {
            $settings['ai_api_key'] = Crypt::encryptString($validated['ai_api_key']);
        } elseif (array_key_exists('ai_api_key', $validated) && $validated['ai_api_key'] === null) {
            $settings['ai_api_key'] = null;
        }

        $workspace->update(['settings' => $settings]);

        return response()->json([
            'success' => true,
            'message' => 'AI settings saved successfully.',
        ]);
    }

    /**
     * Return the current AI settings (mask the API key).
     */
    public function getSettings(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        $settings = $workspace->settings ?? [];

        return response()->json([
            'ai_endpoint' => $settings['ai_endpoint'] ?? null,
            'ai_api_key'  => ! empty($settings['ai_api_key']) ? '••••••••' : null,
            'ai_model'    => $settings['ai_model'] ?? null,
            'ai_configured' => ! empty($settings['ai_endpoint']) && ! empty($settings['ai_api_key']),
        ]);
    }

    /**
     * Fetch available models from the configured AI endpoint.
     * Uses the OpenAI-compatible GET /v1/models endpoint.
     */
    public function fetchModels(Request $request, Workspace $workspace)
    {
        $this->authorize('update', $workspace);

        $settings = $workspace->settings ?? [];

        $endpoint = $settings['ai_endpoint'] ?? null;
        $apiKey   = ! empty($settings['ai_api_key'])
            ? Crypt::decryptString($settings['ai_api_key'])
            : null;

        // Allow passing endpoint/key from request (for testing before save)
        if ($request->filled('ai_endpoint')) {
            $endpoint = $request->input('ai_endpoint');
        }
        if ($request->filled('ai_api_key')) {
            $apiKey = $request->input('ai_api_key');
        }

        if (! $endpoint || ! $apiKey) {
            return response()->json([
                'success' => false,
                'message' => 'AI endpoint and API key are required.',
            ], 422);
        }

        try {
            // Normalise endpoint: strip trailing slash, append /v1/models if needed
            $baseUrl = rtrim($endpoint, '/');
            $modelsUrl = $baseUrl . '/v1/models';

            // Some providers already include /v1 in the endpoint
            if (str_ends_with($baseUrl, '/v1')) {
                $modelsUrl = $baseUrl . '/models';
            }

            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->get($modelsUrl);

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch models: ' . $response->status() . ' ' . $response->reason(),
                ], 422);
            }

            $body = $response->json();

            // OpenAI-compatible response: { "data": [ { "id": "model-name", ... }, ... ] }
            $models = collect($body['data'] ?? [])
                ->pluck('id')
                ->sort()
                ->values()
                ->toArray();

            return response()->json([
                'success' => true,
                'models'  => $models,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not connect to AI endpoint: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Proxy a chat completion request to the configured AI provider.
     * Uses the OpenAI-compatible POST /v1/chat/completions endpoint.
     */
    public function generate(Request $request, Workspace $workspace)
    {
        $this->authorize('view', $workspace);

        $validated = $request->validate([
            'content'         => 'required|string|max:50000',
            'instruction'    => 'nullable|string|max:2000',
            'title'          => 'nullable|string|max:500',
            'is_selection'   => 'nullable|boolean',
            'generate_prompt' => 'nullable|boolean',
        ]);

        $settings = $workspace->settings ?? [];

        $endpoint = $settings['ai_endpoint'] ?? null;
        $apiKey   = ! empty($settings['ai_api_key'])
            ? Crypt::decryptString($settings['ai_api_key'])
            : null;
        $model    = $settings['ai_model'] ?? null;

        if (! $endpoint || ! $apiKey || ! $model) {
            return response()->json([
                'success' => false,
                'message' => 'AI is not configured for this workspace. Please set up AI in workspace settings.',
            ], 422);
        }

        $generatePrompt = $validated['generate_prompt'] ?? false;

        if ($generatePrompt) {
            $systemPrompt = 'You are an expert prompt engineer. Your job is to output a single, ready-to-use PROMPT — i.e. text that the user will copy and paste into an AI assistant (e.g. ChatGPT, Claude) to get a result. '
                . 'The output must be written AS IF the user is speaking to the AI: use second person ("You are...", "Please...", "I need you to..."). '
                . 'Do NOT output a structured document with labels like "Role:", "Context:", "Task:". Do NOT describe the task from the outside. '
                . 'Output ONE block of instructions that an AI can follow directly. Weave in the context (title, project, description) naturally inside that prompt. '
                . 'Example style: "You are a senior software engineer. I have a feature: [title]. Project: [project]. [description]. Please create a technical spec with acceptance criteria, complexity, and effort estimate." '
                . 'Output ONLY this prompt text. No preamble, no meta-commentary. Plain text or simple <p> tags only.';
            $userMessage = $validated['content'];
        } else {
            $isSelection = $validated['is_selection'] ?? false;
            $title = $validated['title'] ?? null;

            $instruction = $validated['instruction']
                ?? 'Rewrite the following content to be clearer, more professional, and better structured.';

            $systemPrompt = 'You are a professional writing assistant integrated into a project management tool. '
                . 'Your task is to improve the quality of text content based on the user\'s instruction. '
                . 'Use rich formatting to make the content more readable and engaging: '
                . '- Use relevant emojis where appropriate (e.g. ✅, 📌, 🔧, 🚀, 💡, ⚠️, 📝, 🎯) '
                . '- Use bullet points (<ul><li>) and numbered lists (<ol><li>) to organize information '
                . '- Use <strong> for emphasis on key terms and <em> for secondary emphasis '
                . '- Use headings (<h3>, <h4>) when structuring longer content '
                . '- Use <p> tags to separate paragraphs properly '
                . 'Always return valid HTML. Do NOT wrap the output in markdown code fences. '
                . 'Do NOT add any preamble, explanation, or commentary — return ONLY the improved content as HTML.';

            // Prevent translation unless explicitly requested
            if (stripos($instruction, 'translate') === false) {
                $systemPrompt .= ' Do NOT translate the content to another language unless the user instruction explicitly requests translation.';
            }

            if ($isSelection) {
                $systemPrompt .= ' The user has selected a specific piece of text from a larger document. '
                    . 'Improve only that selected portion. Keep the result concise and contextually appropriate.';
            }

            // Build the user message with context
            $userMessage = $instruction . "\n\n";

            if ($title) {
                $userMessage .= "Context — this is the description for a task/item titled: \"{$title}\"\n\n";
            }

            if ($isSelection) {
                $userMessage .= "Selected text:\n" . $validated['content'];
            } else {
                $userMessage .= $validated['content'];
            }
        }

        try {
            $baseUrl = rtrim($endpoint, '/');
            $chatUrl = $baseUrl . '/v1/chat/completions';

            if (str_ends_with($baseUrl, '/v1')) {
                $chatUrl = $baseUrl . '/chat/completions';
            }

            $payload = [
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMessage],
                ],
                'max_completion_tokens' => 4096,
            ];

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ])
                ->post($chatUrl, $payload);

            if (! $response->successful()) {
                $body = $response->json();
                $apiMessage = $body['error']['message'] ?? $body['error']['code'] ?? $body['message'] ?? null;
                $detail = $apiMessage
                    ? ' ' . (is_string($apiMessage) ? $apiMessage : json_encode($apiMessage))
                    : ' (' . $response->body() . ')';

                return response()->json([
                    'success' => false,
                    'message' => 'AI request failed: ' . $response->status() . ' ' . $response->reason() . $detail,
                ], 422);
            }

            $body = $response->json();
            $generatedContent = $body['choices'][0]['message']['content'] ?? '';

            return response()->json([
                'success' => true,
                'content' => $generatedContent,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'AI generation failed: ' . $e->getMessage(),
            ], 422);
        }
    }
}
