export const csrfToken = () =>
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

export const filenameFromDisposition = (
    header: string | null,
    fallback: string,
) => {
    const match = header?.match(/filename="?([^"]+)"?/i);

    return match?.[1] ?? fallback;
};

const escapeHtml = (value: string) =>
    value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

const renderInlineMarkdown = (value: string) =>
    escapeHtml(value).replace(
        /\*\*(.+?)\*\*/g,
        '<strong class="font-semibold text-black">$1</strong>',
    );

export const renderSummaryMarkdown = (value: string) => {
    const html: string[] = [];
    let listOpen = false;

    const closeList = () => {
        if (listOpen) {
            html.push('</ul>');
            listOpen = false;
        }
    };

    value
        .replace(/\r\n?/g, '\n')
        .split('\n')
        .forEach((rawLine) => {
            const line = rawLine.trim();

            if (line === '') {
                closeList();

                return;
            }

            const heading = line.match(/^(#{1,6})\s+(.+)$/);

            if (heading) {
                closeList();
                const level = Math.min(4, Math.max(3, heading[1].length));

                html.push(
                    `<h${level} class="mt-5 first:mt-0 text-sm font-semibold uppercase text-blue-700">${renderInlineMarkdown(heading[2])}</h${level}>`,
                );

                return;
            }

            const bullet = line.match(/^[-*]\s+(.+)$/);

            if (bullet) {
                if (!listOpen) {
                    html.push('<ul class="my-3 ml-5 list-disc space-y-2">');
                    listOpen = true;
                }

                html.push(`<li>${renderInlineMarkdown(bullet[1])}</li>`);

                return;
            }

            closeList();
            html.push(
                `<p class="my-3 first:mt-0 last:mb-0">${renderInlineMarkdown(line)}</p>`,
            );
        });

    closeList();

    return html.join('');
};
