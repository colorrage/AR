<div class="p-6 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-700">
    <div class="mb-4 pb-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-2 mb-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Subject:</span>
            <span class="text-lg font-bold text-gray-900 dark:text-white">{{ $subject }}</span>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 p-8 rounded-lg shadow-sm min-h-[400px] prose dark:prose-invert max-w-none">
        {!! $body !!}
    </div>

    <div class="mt-6 flex justify-center text-xs text-gray-400">
        <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</div>
