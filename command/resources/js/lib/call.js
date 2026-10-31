import { toast } from './toast';

/** Can this device place a phone call from a tel: link? Phones yes; laptops and desktops no. */
export const canDial = () => !!window.matchMedia?.('(pointer: coarse)').matches && /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Older browsers, or a page not served over HTTPS.
        const box = Object.assign(document.createElement('textarea'), { value: text });
        box.setAttribute('readonly', '');
        box.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(box);
        box.select();
        const ok = document.execCommand('copy');
        box.remove();
        return ok;
    }
}

/**
 * Click handler for a tel: link. On a phone the dialler opens as normal; on a computer the
 * number is copied instead, with a note to dial it from a phone. Resolves true when copied.
 */
export async function callOrCopy(event, phone, who = '') {
    if (canDial()) return false;
    event.preventDefault();
    const ok = await copy(phone);
    toast(ok
        ? { icon: 'phone', tone: 'good', title: `Copied ${phone}`, body: `${who ? `${who}'s number` : 'The number'} is on your clipboard. Dial it from your phone.`, ms: 5000 }
        : { icon: 'phone', tone: 'warn', title: `Call ${phone}`, body: "Couldn't copy it here. Dial this number from your phone.", ms: 8000 });
    return ok;
}
