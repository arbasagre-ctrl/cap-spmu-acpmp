<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PreventDuplicateSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? 'unnamed');

        if ($routeName === 'storage.local.upload' || str_starts_with($routeName, 'debugbar.')) {
            return $next($request);
        }

        $userKey = $request->user()
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'session:'.$request->session()->getId();

        $actionToken = trim((string) $request->input('_action_token', ''));

        if ($actionToken !== '') {
            $identity = 'token:'.$actionToken;
            $completedSeconds = 600;
        } else {
            $identity = 'fingerprint:'.$this->requestFingerprint($request);
            $completedSeconds = 15;
        }

        $actionHash = hash('sha256', implode('|', [
            $userKey,
            strtoupper($request->method()),
            $routeName,
            $identity,
        ]));

        $lockKey = 'spmu:action-lock:'.$actionHash;
        $completedKey = 'spmu:action-completed:'.$actionHash;

        if (Cache::has($completedKey)) {
            return $this->duplicateResponse(
                $request,
                'This action was already completed. No duplicate transaction was created.'
            );
        }

        $lock = Cache::lock($lockKey, 60);

        if (! $lock->get()) {
            return $this->duplicateResponse(
                $request,
                'This action is already being processed. Please wait for the current request to finish.'
            );
        }

        try {
            if (Cache::has($completedKey)) {
                return $this->duplicateResponse(
                    $request,
                    'This action was already completed. No duplicate transaction was created.'
                );
            }

            $response = $next($request);

            if ($response->getStatusCode() < 400) {
                Cache::put($completedKey, true, now()->addSeconds($completedSeconds));
            }

            return $response;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $exception) {
                Log::warning('Duplicate-submission lock release failed.', [
                    'route' => $routeName,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function duplicateResponse(Request $request, string $message): Response
    {
        Log::notice('Duplicate state-changing request prevented.', [
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'duplicate_prevented' => true,
            ], 409);
        }

        return redirect()->back()->withInput()->with('status', $message);
    }

    private function requestFingerprint(Request $request): string
    {
        $payload = $request->except(['_token', '_action_token']);
        $payload = $this->normalizeValue($payload);
        $files = $this->normalizeFiles($request->allFiles());

        return hash('sha256', json_encode([
            'payload' => $payload,
            'files' => $files,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '');
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }

    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        ksort($files);

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $normalized[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                ];
                continue;
            }

            if (is_array($file)) {
                $normalized[$key] = $this->normalizeFiles($file);
            }
        }

        return $normalized;
    }
}
