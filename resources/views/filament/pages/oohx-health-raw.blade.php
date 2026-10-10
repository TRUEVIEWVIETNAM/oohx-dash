<div class="space-y-3">
    @if($path)
        <div class="text-xs text-gray-500">
            Nguồn: <code>{{ $path }}</code>
        </div>
    @endif

    <pre class="text-xs bg-gray-50 dark:bg-gray-900 rounded-lg p-4 overflow-auto max-h-[60vh] border border-gray-200 dark:border-gray-700 font-mono">{{ $digest ? json_encode($digest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'No digest loaded.' }}</pre>
</div>
