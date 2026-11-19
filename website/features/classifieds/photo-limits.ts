/*
 * Classified ad photos (post-ad / edit-ad wizard): per-category limits from
 * the API and the client-side resize / compress step before each upload.
 *
 * `GET classifieds/categories` (every node) and `GET classifieds/categories/
 * {slug}/attributes` carry `max_photos` (photos per ad) and `photo_max_kb`
 * (size of one photo), both inherited down the tree. Older APIs omit them:
 * the defaults below match the backend's.
 */

export const DEFAULT_MAX_PHOTOS = 20;
export const DEFAULT_PHOTO_MAX_KB = 8192;
/** Longest side after resizing. */
export const MAX_PHOTO_SIDE = 2048;
export const START_QUALITY = 0.85;
export const MIN_QUALITY = 0.5;
/** Originals at or under this size (and the limit) upload untouched: re-encoding would only cost quality. */
export const KEEP_ORIGINAL_BYTES = 1_500_000;
/** Formats the backend accepts and the wizard offers. */
export const PHOTO_TYPES = ["image/jpeg", "image/png", "image/webp"] as const;

export type PhotoLimits = { maxPhotos: number; maxBytes: number; maxKb: number };

type LimitSource = { max_photos?: number | null; photo_max_kb?: number | null } | null | undefined;

const positiveInt = (value: unknown): number | null => (typeof value === "number" && Number.isFinite(value) && value >= 1 ? Math.floor(value) : null);

/** Limits for the chosen category: the attributes response first, then the tree node, then the defaults. */
export function photoLimits(...sources: LimitSource[]): PhotoLimits {
  let maxPhotos: number | null = null;
  let maxKb: number | null = null;
  for (const source of sources) {
    maxPhotos ??= positiveInt(source?.max_photos);
    maxKb ??= positiveInt(source?.photo_max_kb);
  }
  const kb = maxKb ?? DEFAULT_PHOTO_MAX_KB;
  return { maxPhotos: maxPhotos ?? DEFAULT_MAX_PHOTOS, maxKb: kb, maxBytes: kb * 1024 };
}

/** "8 MB", "500 KB", "1.5 MB" for messages. */
export function formatLimit(kb: number): string {
  if (kb < 1024) return `${kb} KB`;
  const mb = kb / 1024;
  return `${Number.isInteger(mb) ? mb : Number(mb.toFixed(1))} MB`;
}

export type PhotoRejection = { name: string; reason: "type" | "count" | "size" };

/**
 * Which picked files may go up, given the photos the ad already has: non-image
 * files are refused, and files past `maxPhotos` are dropped in pick order.
 */
export function planPhotoBatch<F extends { name: string; type: string }>(files: readonly F[], existing: number, limits: Pick<PhotoLimits, "maxPhotos">): { accepted: F[]; rejected: PhotoRejection[] } {
  const accepted: F[] = [];
  const rejected: PhotoRejection[] = [];
  let room = Math.max(0, limits.maxPhotos - existing);
  for (const file of files) {
    if (!(PHOTO_TYPES as readonly string[]).includes(file.type)) rejected.push({ name: file.name, reason: "type" });
    else if (room <= 0) rejected.push({ name: file.name, reason: "count" });
    else {
      accepted.push(file);
      room--;
    }
  }
  return { accepted, rejected };
}

/** Scale (w, h) so the longest side is at most `max`, keeping the aspect ratio. */
export function fitWithin(width: number, height: number, max = MAX_PHOTO_SIDE): { width: number; height: number } {
  const longest = Math.max(width, height);
  if (longest <= max || longest <= 0) return { width, height };
  const scale = max / longest;
  return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/** Upload the original as is? (small enough already, or not something the canvas can re-encode). */
export function keepOriginal(file: { size: number; type: string }, maxBytes: number): boolean {
  if (!(PHOTO_TYPES as readonly string[]).includes(file.type)) return true;
  return file.size <= Math.min(maxBytes, KEEP_ORIGINAL_BYTES);
}

/** Qualities tried in order: 0.85, 0.75, 0.65, 0.55, then the floor. */
export function qualitySteps(start = START_QUALITY, min = MIN_QUALITY, step = 0.1): number[] {
  const out: number[] = [];
  for (let q = start; q > min + 1e-9; q -= step) out.push(Number(q.toFixed(2)));
  out.push(min);
  return out;
}

export type Decoded = { width: number; height: number; source: CanvasImageSource; close?: () => void };

export type ImageCodec = {
  decode: (file: Blob) => Promise<Decoded>;
  /** Draw `source` at width × height and encode it; resolves null when the browser cannot encode `type`. */
  encode: (source: CanvasImageSource, width: number, height: number, type: string, quality: number) => Promise<Blob | null>;
};

export type PreparedPhoto = { ok: true; file: File; compressed: boolean } | { ok: false; reason: "size" | "decode" };

const renamed = (name: string, type: string) => `${name.replace(/\.[^.]+$/, "") || "photo"}.${type === "image/webp" ? "webp" : "jpg"}`;

/**
 * Resize (longest side 2048 px) and re-encode a photo so it fits the
 * category's size limit: WebP where the browser can encode it (keeps PNG
 * transparency), JPEG otherwise, lowering the quality step by step and, as a
 * last resort, the dimensions. Small originals go up untouched; an original
 * that is already under the limit is kept when compressing would not shrink it.
 */
export async function preparePhoto(file: File, maxBytes: number, codec: ImageCodec = browserCodec): Promise<PreparedPhoto> {
  if (keepOriginal(file, maxBytes)) return { ok: true, file, compressed: false };

  let decoded: Decoded;
  try {
    decoded = await codec.decode(file);
  } catch {
    return file.size <= maxBytes ? { ok: true, file, compressed: false } : { ok: false, reason: "decode" };
  }

  try {
    let { width, height } = fitWithin(decoded.width, decoded.height);
    let type: string | null = null;
    for (let shrink = 0; shrink < 4; shrink++) {
      for (const quality of qualitySteps()) {
        let blob: Blob | null = null;
        if (type === null || type === "image/webp") blob = await codec.encode(decoded.source, width, height, "image/webp", quality);
        // Browsers that cannot encode WebP fall back to PNG; use JPEG instead.
        if (!blob || blob.type !== "image/webp") {
          type = "image/jpeg";
          blob = await codec.encode(decoded.source, width, height, "image/jpeg", quality);
        } else type = "image/webp";
        if (!blob) return file.size <= maxBytes ? { ok: true, file, compressed: false } : { ok: false, reason: "decode" };
        if (blob.size <= maxBytes) {
          if (file.size <= maxBytes && blob.size >= file.size) return { ok: true, file, compressed: false };
          return { ok: true, file: new File([blob], renamed(file.name, blob.type), { type: blob.type, lastModified: file.lastModified }), compressed: true };
        }
      }
      ({ width, height } = { width: Math.max(1, Math.round(width * 0.75)), height: Math.max(1, Math.round(height * 0.75)) });
    }
    return file.size <= maxBytes ? { ok: true, file, compressed: false } : { ok: false, reason: "size" };
  } finally {
    decoded.close?.();
  }
}

/** Canvas codec (createImageBitmap honours EXIF orientation; <img> fallback for older Safari). */
export const browserCodec: ImageCodec = {
  async decode(file) {
    if (typeof createImageBitmap === "function") {
      const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
      return { width: bitmap.width, height: bitmap.height, source: bitmap, close: () => bitmap.close() };
    }
    const url = URL.createObjectURL(file);
    try {
      const img = new Image();
      img.decoding = "async";
      img.src = url;
      await img.decode();
      return { width: img.naturalWidth, height: img.naturalHeight, source: img, close: () => URL.revokeObjectURL(url) };
    } catch (error) {
      URL.revokeObjectURL(url);
      throw error;
    }
  },
  encode(source, width, height, type, quality) {
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext("2d");
    if (!ctx) return Promise.resolve(null);
    if (type === "image/jpeg") {
      // JPEG has no alpha: transparent PNG areas become white, not black.
      ctx.fillStyle = "#fff";
      ctx.fillRect(0, 0, width, height);
    }
    ctx.imageSmoothingQuality = "high";
    ctx.drawImage(source, 0, 0, width, height);
    return new Promise((resolve) => canvas.toBlob((blob) => resolve(blob), type, quality));
  },
};

/*
 * One short video per ad (`POST classifieds/ads/{number}/video`). The backend
 * checks the category's `allows_video`, `classified.video_max_seconds` (60)
 * and `classified.video_max_kb` (51200); the attributes response may carry
 * them as `allows_video` / `video_max_seconds` / `video_max_kb` — until it
 * does, the defaults mirror the backend config and the API's own refusal is
 * shown.
 */
export const DEFAULT_VIDEO_MAX_SECONDS = 60;
export const DEFAULT_VIDEO_MAX_KB = 51200;
export const VIDEO_TYPES = ["video/mp4", "video/webm", "video/quicktime"] as const;

export type VideoLimits = { allowed: boolean; maxSeconds: number; maxKb: number };

export function videoLimits(source?: { allows_video?: boolean | null; video_max_seconds?: number | null; video_max_kb?: number | null } | null): VideoLimits {
  return {
    allowed: source?.allows_video !== false,
    maxSeconds: positiveInt(source?.video_max_seconds) ?? DEFAULT_VIDEO_MAX_SECONDS,
    maxKb: positiveInt(source?.video_max_kb) ?? DEFAULT_VIDEO_MAX_KB,
  };
}

/** Why a picked video cannot go up, or null. `duration` is in seconds (null when unreadable). */
export function checkVideo(file: { type: string; size: number }, duration: number | null, limits: VideoLimits): "type" | "size" | "duration" | "unreadable" | null {
  if (!(VIDEO_TYPES as readonly string[]).includes(file.type)) return "type";
  if (file.size > limits.maxKb * 1024) return "size";
  if (duration === null || !Number.isFinite(duration) || duration <= 0) return "unreadable";
  if (Math.ceil(duration) > limits.maxSeconds) return "duration";
  return null;
}

/**
 * The video's length in seconds from its metadata, or null when the browser cannot read it.
 * WebM written by MediaRecorder (browser and many Android recorders) has no duration in its
 * header, so `duration` is Infinity until the element seeks to the end; seek far past the end
 * once and read the real duration from `durationchange` (bounded by a timeout).
 */
export function readVideoDuration(file: Blob, timeoutMs = 5_000): Promise<number | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const video = document.createElement("video");
    let settled = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const done = (value: number | null) => {
      if (settled) return;
      settled = true;
      if (timer) clearTimeout(timer);
      URL.revokeObjectURL(url);
      resolve(value);
    };
    const finite = () => (Number.isFinite(video.duration) && video.duration > 0 ? video.duration : null);
    video.preload = "metadata";
    video.onloadedmetadata = () => {
      const duration = finite();
      if (duration !== null || video.duration !== Infinity) return done(duration);
      video.ondurationchange = () => {
        const real = finite();
        if (real !== null) done(real);
      };
      timer = setTimeout(() => done(finite()), timeoutMs);
      video.currentTime = Number.MAX_SAFE_INTEGER;
    };
    video.onerror = () => done(null);
    video.src = url;
  });
}
