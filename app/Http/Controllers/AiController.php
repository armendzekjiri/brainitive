<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Project;
use App\Models\Workspace;
use App\Support\OutboundUrl;
use App\Support\SafeHtml;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
            'ai_api_key' => 'nullable|string|max:500',
            'ai_model' => 'nullable|string|max:255',
        ]);

        $settings = $workspace->settings ?? [];

        $settings['ai_endpoint'] = $validated['ai_endpoint'] ?? null;
        $settings['ai_model'] = $validated['ai_model'] ?? null;

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
            'ai_api_key' => ! empty($settings['ai_api_key']) ? '••••••••' : null,
            'ai_model' => $settings['ai_model'] ?? null,
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
        $apiKey = ! empty($settings['ai_api_key'])
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
            $modelsUrl = $baseUrl.'/v1/models';

            // Some providers already include /v1 in the endpoint
            if (str_ends_with($baseUrl, '/v1')) {
                $modelsUrl = $baseUrl.'/models';
            }

            $options = OutboundUrl::options($modelsUrl, OutboundUrl::allowPrivateAiEndpoints());
            if ($options === null) {
                return response()->json(['success' => false, 'message' => 'This AI endpoint isn’t allowed. Use a public http(s) address; for a server on your own network the instance admin can set AI_ALLOW_PRIVATE_ENDPOINTS=true.'], 422);
            }

            $response = Http::timeout(15)
                ->withOptions($options)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->get($modelsUrl);

            if (! $response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch models: '.$response->status().' '.$response->reason(),
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
                'models' => $models,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not connect to AI endpoint: '.$e->getMessage(),
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
            'content' => 'required|string|max:50000',
            'instruction' => 'nullable|string|max:2000',
            'title' => 'nullable|string|max:500',
            'is_selection' => 'nullable|boolean',
            'generate_prompt' => 'nullable|boolean',
            'project_id' => 'nullable|integer',
        ]);

        $generatePrompt = $validated['generate_prompt'] ?? false;

        if ($generatePrompt) {
            $systemPrompt = <<<'PROMPT'
            You are a prompt generator embedded in a Jira-like app.
            
            Your job: transform the user's card text into ONE excellent, copy-paste prompt for a separate AI assistant.
            
            Output rules:
            - Output ONLY the final prompt text (plain text or simple <p> HTML). No preamble, no explanations, no analysis.
            - The prompt must be written as if the user is speaking to the AI assistant (first person: "I", "my", "please").
            - Do NOT use labeled template sections like "Role:", "Context:", "Task:", "Input:", "Output:".
            - Do NOT repeat the raw card text verbatim unless it’s necessary; instead, distill and integrate it naturally.
            - Do not invent missing requirements. If key info is missing, include a short list of precise follow-up questions at the end of the prompt.
            
            Quality requirements for the prompt you output:
            - Start the prompt with: "You are a senior software engineer and technical product partner."
            - Instruct the AI assistant to first restate the goal in 1–2 sentences to confirm understanding.
            - Then instruct the AI assistant to produce an implementation-ready result with: scope/non-scope, approach, edge cases, acceptance criteria, test plan, and effort/complexity estimate.
            - If relevant, also require: API/DB changes, validation & error handling, security, performance, logging/observability, and rollout/migration notes.
            
            Now generate the single prompt.
            PROMPT;
            $userMessage = $validated['content'];
        } else {
            $isSelection = $validated['is_selection'] ?? false;
            $title = $validated['title'] ?? null;

            $instruction = $validated['instruction']
                ?? 'Rewrite the following content to be clearer, more professional, and better structured.';

            $systemPrompt = 'You are a professional writing assistant integrated into a project management tool. '
                .'Your task is to improve the quality of text content based on the user\'s instruction. '
                .'Use rich formatting to make the content more readable and engaging: '
                .'- Use relevant emojis where appropriate (e.g. ✅, 📌, 🔧, 🚀, 💡, ⚠️, 📝, 🎯) '
                .'- Use bullet points (<ul><li>) and numbered lists (<ol><li>) to organize information '
                .'- Use <strong> for emphasis on key terms and <em> for secondary emphasis '
                .'- Use headings (<h3>, <h4>) when structuring longer content '
                .'- Use <p> tags to separate paragraphs properly '
                .'Always return valid HTML. Do NOT wrap the output in markdown code fences. '
                .'Do NOT add any preamble, explanation, or commentary — return ONLY the improved content as HTML.';

            // Prevent translation unless explicitly requested
            if (stripos($instruction, 'translate') === false) {
                $systemPrompt .= ' Do NOT translate the content to another language unless the user instruction explicitly requests translation.';
            }

            if ($isSelection) {
                $systemPrompt .= ' The user has selected a specific piece of text from a larger document. '
                    .'Improve only that selected portion. Keep the result concise and contextually appropriate.';
            }

            // Build the user message with context
            $userMessage = $instruction."\n\n";

            if ($title) {
                $userMessage .= "Context — this is the description for a task/item titled: \"{$title}\"\n\n";
            }

            if ($isSelection) {
                $userMessage .= "Selected text:\n".$validated['content'];
            } else {
                $userMessage .= $validated['content'];
            }
        }

        $systemPrompt .= $this->projectContext($request, $workspace, $validated['project_id'] ?? null);

        return $this->chat($workspace, $systemPrompt, $userMessage);
    }

    /**
     * Project assistant (project overview): summary, task draft or implementation prompt,
     * grounded in the project description. The result is only returned — the user reviews it
     * and decides to copy, save as a draft doc or (summary) replace the description.
     */
    public function projectAssist(Request $request, Workspace $workspace, Project $project)
    {
        $this->authorize('view', $project);
        abort_unless($project->workspace_id === $workspace->id, 404);

        $validated = $request->validate([
            'mode' => 'required|in:summary,task,prompt',
            'request' => 'nullable|required_unless:mode,summary|string|max:2000',
        ]);

        $description = $this->plainDescription($project);
        if (mb_strlen($description) < 40) {
            return response()->json([
                'success' => false,
                'needs_description' => true,
                'message' => 'The project description is too short for the AI to work with. Add a few sentences about goals, users and scope under Project settings → General.',
            ], 422);
        }

        $base = 'You help a team working in a project management tool. Use ONLY the project information given; do not invent facts, names, numbers or features. '
            .'Return valid HTML (<p>, <h3>, <ul>/<ol>, <strong>) without markdown code fences and without preamble.';

        [$system, $user] = match ($validated['mode']) {
            'summary' => [
                $base.' Write a concise project summary: one short paragraph on the purpose, then 3–6 bullet points on goals/scope, and one line on who it is for if stated.',
                'Summarize this project.',
            ],
            'task' => [
                $base.' Write a ready-to-use task description for this project: a one-sentence goal, context (why, in this project), requirements as a checklist, and acceptance criteria. If key information is missing, end with 1–3 open questions.',
                'Task to describe: '.$validated['request'],
            ],
            'prompt' => [
                $base.' Write ONE copy-paste prompt for an AI coding assistant, in first person, that explains the project briefly and asks for an implementation-ready plan of the requested work: scope/non-scope, approach, edge cases, acceptance criteria and tests. Output only the prompt.',
                'Work to get a prompt for: '.$validated['request'],
            ],
        };

        return $this->chat($workspace, $system.$this->projectContext($request, $workspace, $project->id), $user);
    }

    /**
     * Save a reviewed assistant result as a draft doc in the project (only the author sees it
     * until published).
     */
    public function saveProjectAssist(Request $request, Workspace $workspace, Project $project)
    {
        abort_unless($project->workspace_id === $workspace->id, 404);
        $this->authorize('create', [Page::class, $project]);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'html' => 'required|string|max:200000',
        ]);

        $page = $project->allPages()->create([
            'name' => $validated['title'],
            'type' => 'page',
            'icon' => '✨',
            'created_by' => $request->user()->id,
            'content' => ['type' => 'lexical', 'html' => SafeHtml::clean($validated['html'])],
        ]);

        return response()->json(['success' => true, 'url' => route('pages.show', [$workspace->slug, $project->slug, $page->uuid])]);
    }

    /** Appended to the system prompt when the request comes from inside a project. */
    private function projectContext(Request $request, Workspace $workspace, ?int $projectId): string
    {
        if (! $projectId) {
            return '';
        }

        $project = Project::where('workspace_id', $workspace->id)->find($projectId);
        if (! $project || $request->user()->cannot('view', $project)) {
            return '';
        }

        $description = $this->plainDescription($project);

        return "\n\nProject context — keep the result consistent with this project; don't invent facts beyond it:\n"
            ."Project: {$project->name}\n"
            .($description !== '' ? "Description: {$description}\n" : '');
    }

    private function plainDescription(Project $project): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '</li>'], "\n", (string) $project->description)));

        return Str::limit(trim(preg_replace("/\n{3,}/", "\n\n", $text)), 4000);
    }

    /**
     * One chat completion against the workspace's AI endpoint (SSRF-guarded).
     */
    private function chat(Workspace $workspace, string $systemPrompt, string $userMessage)
    {
        $settings = $workspace->settings ?? [];
        $endpoint = $settings['ai_endpoint'] ?? null;
        $apiKey = ! empty($settings['ai_api_key']) ? Crypt::decryptString($settings['ai_api_key']) : null;
        $model = $settings['ai_model'] ?? null;

        if (! $endpoint || ! $apiKey || ! $model) {
            return response()->json([
                'success' => false,
                'message' => 'AI is not configured for this workspace. Please set up AI in workspace settings.',
            ], 422);
        }

        try {
            $baseUrl = rtrim($endpoint, '/');
            $chatUrl = str_ends_with($baseUrl, '/v1') ? $baseUrl.'/chat/completions' : $baseUrl.'/v1/chat/completions';

            $options = OutboundUrl::options($chatUrl, OutboundUrl::allowPrivateAiEndpoints());
            if ($options === null) {
                return response()->json(['success' => false, 'message' => 'This AI endpoint isn’t allowed. Use a public http(s) address; for a server on your own network the instance admin can set AI_ALLOW_PRIVATE_ENDPOINTS=true.'], 422);
            }

            $response = Http::timeout(60)
                ->withOptions($options)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($chatUrl, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userMessage],
                    ],
                    'max_completion_tokens' => 4096,
                ]);

            if (! $response->successful()) {
                $body = $response->json();
                $apiMessage = $body['error']['message'] ?? $body['error']['code'] ?? $body['message'] ?? null;
                $detail = $apiMessage ? ' '.(is_string($apiMessage) ? $apiMessage : json_encode($apiMessage)) : '';

                return response()->json([
                    'success' => false,
                    'message' => Str::limit('AI request failed: '.$response->status().' '.$response->reason().$detail, 300),
                ], 422);
            }

            return response()->json([
                'success' => true,
                'content' => $response->json('choices.0.message.content') ?? '',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => Str::limit('AI generation failed: '.$e->getMessage(), 300),
            ], 422);
        }
    }
}
