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
        '<strong class="font-semibold text-slate-950">$1</strong>',
    );

export const renderSummaryMarkdown = (value: string) => {
    const lines = value.split(/\r?\n/);
    const html: string[] = [];
    let listItems: string[] = [];

    const flushList = () => {
        if (listItems.length === 0) {
            return;
        }

        html.push(
            `<ul class="my-3 space-y-2 pl-5 text-sm leading-6 text-slate-700">${listItems.join('')}</ul>`,
        );
        listItems = [];
    };

    lines.forEach((rawLine) => {
        const line = rawLine.trim();

        if (line === '') {
            flushList();

            return;
        }

        const heading = line.match(/^(#{1,3})\s+(.+)$/);

        if (heading) {
            flushList();
            const level = heading[1].length;
            const classes =
                level === 1
                    ? 'mt-1 text-xl font-semibold text-slate-950'
                    : 'mt-5 text-base font-semibold text-slate-950';

            html.push(
                `<h${Math.min(level + 1, 4)} class="${classes}">${renderInlineMarkdown(heading[2])}</h${Math.min(level + 1, 4)}>`,
            );

            return;
        }

        const bullet = line.match(/^[-*]\s+(.+)$/);

        if (bullet) {
            listItems.push(
                `<li class="list-disc">${renderInlineMarkdown(bullet[1])}</li>`,
            );

            return;
        }

        flushList();
        html.push(
            `<p class="my-3 text-sm leading-6 text-slate-700">${renderInlineMarkdown(line)}</p>`,
        );
    });

    flushList();

    return html.join('');
};
