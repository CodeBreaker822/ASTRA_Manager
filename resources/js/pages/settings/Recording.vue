<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { useRecordingSettings } from '@/composables/useRecordingSettings';

const { captureScreenAudio, updateCaptureScreenAudio } = useRecordingSettings();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Recording settings',
                href: '/settings/recording',
            },
        ],
    },
});
</script>

<template>
    <Head title="Recording settings" />

    <h1 class="sr-only">Recording settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Live recording"
            description="Choose which audio JERVA captures in this browser"
        />

        <section
            class="rounded-lg border border-blue-200 bg-blue-50/50 p-4 sm:p-5"
            aria-labelledby="capture-screen-audio-title"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2
                        id="capture-screen-audio-title"
                        class="text-sm font-semibold text-slate-950"
                    >
                        Capture screen audio during live recording
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-slate-600">
                        Mix audio from a shared browser tab, window, or screen
                        with your microphone. Video is never recorded or
                        uploaded.
                    </p>
                </div>

                <button
                    type="button"
                    role="switch"
                    :aria-checked="captureScreenAudio"
                    aria-labelledby="capture-screen-audio-title"
                    data-test="capture-screen-audio-toggle"
                    :class="[
                        'relative mt-0.5 inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors outline-none focus-visible:ring-2 focus-visible:ring-blue-300 focus-visible:ring-offset-2',
                        captureScreenAudio ? 'bg-blue-600' : 'bg-slate-300',
                    ]"
                    @click="updateCaptureScreenAudio(!captureScreenAudio)"
                >
                    <span
                        aria-hidden="true"
                        :class="[
                            'pointer-events-none inline-block size-5 rounded-full bg-white shadow-sm transition-transform',
                            captureScreenAudio
                                ? 'translate-x-5'
                                : 'translate-x-0',
                        ]"
                    />
                </button>
            </div>

            <div
                class="mt-4 rounded-lg border border-blue-100 bg-white p-3 text-xs leading-5 text-slate-600"
            >
                <p class="font-semibold text-slate-800">How it works</p>
                <p class="mt-1">
                    Your browser will show its sharing picker each time a live
                    recording starts. Select a source and enable its
                    <span class="font-semibold text-slate-800"
                        >Share audio</span
                    >
                    option. Available audio sources depend on your browser and
                    operating system.
                </p>
                <p class="mt-2">
                    This privacy-sensitive setting is off by default, saved only
                    in this browser, and applies to the next live recording.
                </p>
            </div>
        </section>
    </div>
</template>
