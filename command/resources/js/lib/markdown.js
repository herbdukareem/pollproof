/**
 * Just enough Markdown for the assistant's answers: paragraphs, **bold**, *italic*, `code`,
 * headings, bulleted and numbered lists, and pipe tables. Everything is escaped first, so the
 * answer can never inject HTML into the page.
 */
const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

function inline(s) {
    return esc(s)
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
}

const isTableRow = (l) => /^\s*\|.*\|\s*$/.test(l);
const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

export function renderMarkdown(text) {
    const lines = String(text ?? '').replace(/\r/g, '').split('\n');
    const out = [];
    let i = 0;
    while (i < lines.length) {
        const line = lines[i];
        if (!line.trim()) { i++; continue; }

        const h = line.match(/^(#{1,4})\s+(.*)$/);
        if (h) { out.push(`<p class="md-h">${inline(h[2])}</p>`); i++; continue; }

        if (isTableRow(line) && isTableRow(lines[i + 1] ?? '') && /^[\s|:-]+$/.test(lines[i + 1])) {
            const head = cells(line);
            i += 2;
            const body = [];
            while (i < lines.length && isTableRow(lines[i])) body.push(cells(lines[i++]));
            out.push(`<div class="md-table"><table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
            continue;
        }

        const bullet = /^\s*[-*•]\s+(.*)$/;
        const numbered = /^\s*\d+[.)]\s+(.*)$/;
        if (bullet.test(line) || numbered.test(line)) {
            const ordered = numbered.test(line);
            const re = ordered ? numbered : bullet;
            const items = [];
            while (i < lines.length && re.test(lines[i])) items.push(lines[i++].match(re)[1]);
            out.push(`<${ordered ? 'ol' : 'ul'}>${items.map((it) => `<li>${inline(it)}</li>`).join('')}</${ordered ? 'ol' : 'ul'}>`);
            continue;
        }

        const para = [];
        while (i < lines.length && lines[i].trim() && !/^(#{1,4})\s/.test(lines[i]) && !bullet.test(lines[i]) && !numbered.test(lines[i]) && !isTableRow(lines[i])) para.push(lines[i++]);
        out.push(`<p>${para.map(inline).join('<br>')}</p>`);
    }
    return out.join('');
}
