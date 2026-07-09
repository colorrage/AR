<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
            Campaign Report: {{ $record->name }}
        </h1>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Recipients</div>
            <div class="mt-2 text-3xl font-bold">{{ $record->total_recipients }}</div>
        </div>
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm text-green-600">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Sent Successfully</div>
            <div class="mt-2 text-3xl font-bold">{{ $record->sent_count }}</div>
        </div>
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm text-red-600">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Failed</div>
            <div class="mt-2 text-3xl font-bold">{{ $record->failed_count }}</div>
        </div>
        <div class="p-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm text-primary-600">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Success Rate</div>
            <div class="mt-2 text-3xl font-bold">
                {{ $record->total_recipients > 0 ? round(($record->sent_count / $record->total_recipients) * 100, 1) : 0 }}%
            </div>
        </div>
    </div>

    <div class="p-6 bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm">
        <h3 class="text-lg font-semibold mb-4">Engagement (Draft)</h3>
        <p class="text-gray-500 italic text-sm">Engagement tracking (opens/clicks) will be implemented soon.</p>
    </div>
</div>
