<script lang="ts">
import { Head } from '@inertiajs/vue3';
import { defineComponent, h } from 'vue';
import type { PropType, VNode } from 'vue';
import type { MarketingSeo } from '@/types/marketing';

const meta = (headKey: string, attributes: Record<string, string>): VNode =>
    h('meta', { 'head-key': headKey, ...attributes });

export default defineComponent({
    name: 'SeoHead',
    props: {
        seo: {
            type: Object as PropType<MarketingSeo>,
            required: true,
        },
    },
    setup(props) {
        return () => {
            const tags: VNode[] = [
                meta('description', {
                    name: 'description',
                    content: props.seo.description,
                }),
                meta('robots', {
                    name: 'robots',
                    content: props.seo.robots,
                }),
                h('link', {
                    'head-key': 'canonical',
                    rel: 'canonical',
                    href: props.seo.canonical_url,
                }),
                meta('og:type', {
                    property: 'og:type',
                    content: props.seo.type,
                }),
                meta('og:site_name', {
                    property: 'og:site_name',
                    content: 'JERVA Transcriber',
                }),
                meta('og:title', {
                    property: 'og:title',
                    content: props.seo.title,
                }),
                meta('og:description', {
                    property: 'og:description',
                    content: props.seo.description,
                }),
                meta('og:url', {
                    property: 'og:url',
                    content: props.seo.canonical_url,
                }),
                meta('og:image', {
                    property: 'og:image',
                    content: props.seo.image_url,
                }),
                meta('twitter:card', {
                    name: 'twitter:card',
                    content: 'summary_large_image',
                }),
                meta('twitter:title', {
                    name: 'twitter:title',
                    content: props.seo.title,
                }),
                meta('twitter:description', {
                    name: 'twitter:description',
                    content: props.seo.description,
                }),
                meta('twitter:image', {
                    name: 'twitter:image',
                    content: props.seo.image_url,
                }),
            ];

            if (props.seo.structured_data) {
                tags.push(
                    h(
                        'script',
                        {
                            'head-key': 'structured-data',
                            type: 'application/ld+json',
                        },
                        JSON.stringify(props.seo.structured_data).replace(
                            /</g,
                            '\\u003c',
                        ),
                    ),
                );
            }

            return h(Head, { title: props.seo.title }, { default: () => tags });
        };
    },
});
</script>
