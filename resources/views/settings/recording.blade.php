<x-layouts.settings title="Recording settings">
    <h1 class="sr-only">Recording settings</h1>

    <div class="space-y-6">
        <x-ui.heading
            variant="small"
            title="Live recording"
            description="Choose which audio JERVA captures in this browser"
        />

        {{-- Browser-local preference, same localStorage key the recorder reads. --}}
        <section
            class="rounded-lg border border-blue-200 bg-blue-50/50 p-4 sm:p-5"
            aria-labelledby="capture-screen-audio-title"
            x-data="{
                enabled: localStorage.getItem('jerva.capture-screen-audio') === 'true',
                toggle() {
                    this.enabled = !this.enabled;
                    try {
                        localStorage.setItem('jerva.capture-screen-audio', String(this.enabled));
                    } catch (e) {
                        // Keep the preference for this page when storage is blocked.
                    }
                },
            }"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="capture-screen-audio-title" class="text-sm font-semibold text-slate-950">
                        Capture screen audio during live recording
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Mix audio from a shared browser tab, window, or screen with your microphone.
                        Video is never recorded or uploaded.
                    </p>
                </div>

                <button
                    type="button"
                    role="switch"
                    x-bind:aria-checked="enabled"
                    aria-labelledby="capture-screen-audio-title"
                    data-test="capture-screen-audio-toggle"
                    x-on:click="toggle()"
                    class="relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-300 focus-visible:ring-offset-2"
                    x-bind:class="enabled ? 'bg-blue-600' : 'bg-slate-300'"
                >
                    <span
                        aria-hidden="true"
                        class="pointer-events-none inline-block size-5 rounded-full bg-white shadow-sm transition-transform"
                        x-bind:class="enabled ? 'translate-x-5' : 'translate-x-0'"
                    ></span>
                </button>
            </div>

            <div class="mt-4 rounded-lg border border-blue-100 bg-white p-3 text-xs leading-5 text-slate-600">
                <p class="font-semibold text-slate-800">How it works</p>
                <p class="mt-1">
                    Your browser will show its sharing picker each time a live recording starts. Select a
                    source and enable its <span class="font-semibold text-slate-800">Share audio</span>
                    option. Available audio sources depend on your browser and operating system.
                </p>
                <p class="mt-2">
                    This privacy-sensitive setting is off by default, saved only in this browser, and
                    applies to the next live recording.
                </p>
            </div>
        </section>
    </div>
</x-layouts.settings>
