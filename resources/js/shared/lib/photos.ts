/**
 * Photos picked or shot on the phone are made smaller in the browser before they go to AI: an invoice stays
 * readable at 1600 px, and the upload is a few hundred KB instead of several MB.
 */
export interface Photo {
    /** object URL for the preview */
    url: string;
    /** base64 JPEG without the data: prefix */
    data: string;
    file: File;
}

export const MAX_SIDE = 1600;

export async function shrink(file: File, maxSide = MAX_SIDE, quality = 0.85): Promise<string> {
    const bitmap = await loadBitmap(file);
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const w = Math.max(1, Math.round(bitmap.width * scale));
    const h = Math.max(1, Math.round(bitmap.height * scale));
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('canvas');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bitmap as CanvasImageSource, 0, 0, w, h);
    if ('close' in bitmap && typeof bitmap.close === 'function') bitmap.close();
    return stripPrefix(canvas.toDataURL('image/jpeg', quality));
}

export function stripPrefix(dataUrl: string): string {
    const i = dataUrl.indexOf('base64,');
    return i >= 0 ? dataUrl.slice(i + 7) : dataUrl;
}

async function loadBitmap(file: File): Promise<ImageBitmap | HTMLImageElement> {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch {
            /* fall back to <img> */
        }
    }
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return img;
    } finally {
        URL.revokeObjectURL(url);
    }
}

export async function toPhotos(files: FileList | File[], limit: number): Promise<Photo[]> {
    const list = Array.from(files).filter((f) => f.type.startsWith('image/')).slice(0, limit);
    const out: Photo[] = [];
    for (const file of list) out.push({ url: URL.createObjectURL(file), data: await shrink(file), file });
    return out;
}

/** The base64 JPEG back as a file (to upload as a product image). */
export function photoFile(p: Photo, name = 'anh.jpg'): File {
    const bin = atob(p.data);
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return new File([bytes], name, { type: 'image/jpeg' });
}
