import { describe, expect, it, vi } from "vitest";
import {
  DEFAULT_MAX_PHOTOS,
  DEFAULT_PHOTO_MAX_KB,
  fitWithin,
  formatLimit,
  keepOriginal,
  photoLimits,
  planPhotoBatch,
  preparePhoto,
  qualitySteps,
  type ImageCodec,
} from "@/features/classifieds/photo-limits";

const file = (name: string, type: string, size: number) => new File([new Uint8Array(size)], name, { type });

describe("classified photo limits", () => {
  it("prefers the attributes response, then the tree node, then 20 / 8192 KB", () => {
    expect(photoLimits()).toEqual({ maxPhotos: DEFAULT_MAX_PHOTOS, maxKb: DEFAULT_PHOTO_MAX_KB, maxBytes: 8192 * 1024 });
    expect(photoLimits({ max_photos: 10, photo_max_kb: null }, { max_photos: 30, photo_max_kb: 2048 })).toEqual({ maxPhotos: 10, maxKb: 2048, maxBytes: 2048 * 1024 });
    expect(photoLimits(undefined, { max_photos: 5 })).toMatchObject({ maxPhotos: 5, maxKb: DEFAULT_PHOTO_MAX_KB });
    expect(photoLimits({ max_photos: 0, photo_max_kb: -1 })).toMatchObject({ maxPhotos: DEFAULT_MAX_PHOTOS, maxKb: DEFAULT_PHOTO_MAX_KB });
  });

  it("formats the size limit for messages", () => {
    expect(formatLimit(8192)).toBe("8 MB");
    expect(formatLimit(1536)).toBe("1.5 MB");
    expect(formatLimit(500)).toBe("500 KB");
  });

  it("drops files past the count limit and refuses non-photos", () => {
    const picked = [file("a.jpg", "image/jpeg", 10), file("doc.pdf", "application/pdf", 10), file("b.png", "image/png", 10), file("c.webp", "image/webp", 10), file("d.gif", "image/gif", 10)];
    const plan = planPhotoBatch(picked, 18, { maxPhotos: 20 });
    expect(plan.accepted.map((f) => f.name)).toEqual(["a.jpg", "b.png"]);
    expect(plan.rejected).toEqual([
      { name: "doc.pdf", reason: "type" },
      { name: "c.webp", reason: "count" },
      { name: "d.gif", reason: "type" },
    ]);
    expect(planPhotoBatch([file("a.jpg", "image/jpeg", 1)], 25, { maxPhotos: 20 }).rejected).toEqual([{ name: "a.jpg", reason: "count" }]);
  });
});

describe("classified photo compression decisions", () => {
  it("fits the longest side within 2048 px keeping the aspect ratio", () => {
    expect(fitWithin(4000, 3000)).toEqual({ width: 2048, height: 1536 });
    expect(fitWithin(3000, 6000)).toEqual({ width: 1024, height: 2048 });
    expect(fitWithin(1200, 800)).toEqual({ width: 1200, height: 800 });
  });

  it("keeps small originals and anything that is not a JPEG / PNG / WebP", () => {
    expect(keepOriginal({ size: 900_000, type: "image/jpeg" }, 8 * 1024 * 1024)).toBe(true);
    expect(keepOriginal({ size: 3_000_000, type: "image/jpeg" }, 8 * 1024 * 1024)).toBe(false);
    expect(keepOriginal({ size: 900_000, type: "image/png" }, 500 * 1024)).toBe(false);
    expect(keepOriginal({ size: 9_000_000, type: "image/gif" }, 1024)).toBe(true);
  });

  it("steps the quality down from 0.85 to 0.5", () => {
    expect(qualitySteps()).toEqual([0.85, 0.75, 0.65, 0.55, 0.5]);
  });
});

/** A fake canvas codec: the encoded size shrinks with quality and pixel count. */
function codec(options: { webp?: boolean; bytesPerPixelAtQ1?: number; failDecode?: boolean } = {}): ImageCodec & { calls: { type: string; quality: number; width: number; height: number }[] } {
  const calls: { type: string; quality: number; width: number; height: number }[] = [];
  return {
    calls,
    decode: vi.fn(async () => {
      if (options.failDecode) throw new Error("bad image");
      return { width: 4000, height: 3000, source: {} as CanvasImageSource };
    }),
    encode: vi.fn(async (_source, width, height, type, quality) => {
      calls.push({ type, quality, width, height });
      const actual = type === "image/webp" && options.webp === false ? "image/png" : type;
      return new Blob([new Uint8Array(Math.round(width * height * (options.bytesPerPixelAtQ1 ?? 1) * quality))], { type: actual });
    }),
  };
}

describe("preparePhoto", () => {
  it("uploads small originals untouched", async () => {
    const c = codec();
    const small = file("small.jpg", "image/jpeg", 200_000);
    expect(await preparePhoto(small, 8192 * 1024, c)).toEqual({ ok: true, file: small, compressed: false });
    expect(c.decode).not.toHaveBeenCalled();
  });

  it("resizes to 2048 px and encodes WebP at 0.85 when that fits", async () => {
    const c = codec();
    const big = file("IMG_0001.JPG", "image/jpeg", 6_000_000);
    const result = await preparePhoto(big, 8192 * 1024, c);
    expect(result).toMatchObject({ ok: true, compressed: true });
    if (!result.ok) throw new Error("expected ok");
    expect(result.file.name).toBe("IMG_0001.webp");
    expect(result.file.type).toBe("image/webp");
    expect(c.calls[0]).toEqual({ type: "image/webp", quality: 0.85, width: 2048, height: 1536 });
  });

  it("falls back to JPEG when the browser cannot encode WebP and lowers the quality until it fits", async () => {
    const c = codec({ webp: false });
    // 2048×1536 px: q0.85 ≈ 2.67 MB, q0.75 ≈ 2.36 MB, q0.65 ≈ 2.04 MB → the limit is 2.1 MB.
    const result = await preparePhoto(file("p.png", "image/png", 9_000_000), 2100 * 1024, c);
    expect(result).toMatchObject({ ok: true, compressed: true });
    if (!result.ok) throw new Error("expected ok");
    expect(result.file.type).toBe("image/jpeg");
    expect(result.file.name).toBe("p.jpg");
    expect(c.calls.filter((x) => x.type === "image/jpeg").map((x) => x.quality)).toEqual([0.85, 0.75, 0.65]);
  });

  it("shrinks the dimensions when the lowest quality is still too big", async () => {
    const c = codec();
    const result = await preparePhoto(file("huge.jpg", "image/jpeg", 20_000_000), 1000 * 1024, c);
    expect(result).toMatchObject({ ok: true, compressed: true });
    expect(c.calls.at(-1)!.width).toBeLessThan(2048);
  });

  it("refuses a photo that cannot get under the limit", async () => {
    const c = codec({ bytesPerPixelAtQ1: 100 });
    expect(await preparePhoto(file("x.jpg", "image/jpeg", 20_000_000), 100 * 1024, c)).toEqual({ ok: false, reason: "size" });
  });

  it("keeps an under-limit original when compressing would not shrink it, or when it cannot be decoded", async () => {
    const bigger = codec({ bytesPerPixelAtQ1: 5 });
    const original = file("ok.jpg", "image/jpeg", 2_000_000);
    expect(await preparePhoto(original, 8192 * 1024, bigger)).toEqual({ ok: true, file: original, compressed: false });
    expect(await preparePhoto(original, 8192 * 1024, codec({ failDecode: true }))).toEqual({ ok: true, file: original, compressed: false });
    expect(await preparePhoto(file("bad.jpg", "image/jpeg", 9_000_000), 8192 * 1024, codec({ failDecode: true }))).toEqual({ ok: false, reason: "decode" });
  });
});

describe("classified ad video", () => {
  it("defaults to the backend config and honours category values when sent", async () => {
    const { videoLimits } = await import("@/features/classifieds/photo-limits");
    expect(videoLimits()).toEqual({ allowed: true, maxSeconds: 60, maxKb: 51200 });
    expect(videoLimits({ allows_video: false, video_max_seconds: 30, video_max_kb: 10240 })).toEqual({ allowed: false, maxSeconds: 30, maxKb: 10240 });
  });

  it("checks type, size and length before upload", async () => {
    const { checkVideo, videoLimits } = await import("@/features/classifieds/photo-limits");
    const limits = videoLimits({ video_max_seconds: 60, video_max_kb: 1024 });
    expect(checkVideo({ type: "video/mp4", size: 500_000 }, 59.4, limits)).toBeNull();
    expect(checkVideo({ type: "video/x-msvideo", size: 10 }, 5, limits)).toBe("type");
    expect(checkVideo({ type: "video/webm", size: 2_000_000 }, 5, limits)).toBe("size");
    expect(checkVideo({ type: "video/quicktime", size: 10 }, 60.2, limits)).toBe("duration");
    expect(checkVideo({ type: "video/mp4", size: 10 }, null, limits)).toBe("unreadable");
  });
});

/**
 * Browser test SOQ-15: a WebM recorded with MediaRecorder reports
 * `duration = Infinity` until the element seeks to its end, so the wizard
 * refused it as "We couldn't read this video".
 */
describe("readVideoDuration", () => {
  type FakeVideo = { preload: string; src: string; duration: number; currentTime: number; onloadedmetadata?: () => void; ondurationchange?: () => void; onerror?: () => void };
  const withVideo = (setup: (video: FakeVideo) => void) => {
    const video: FakeVideo = { preload: "", src: "", duration: NaN, currentTime: 0 };
    const create = document.createElement.bind(document);
    vi.spyOn(document, "createElement").mockImplementation(((tag: string) => (tag === "video" ? (video as unknown as HTMLElement) : create(tag))) as typeof document.createElement);
    URL.createObjectURL = vi.fn(() => "blob:video");
    URL.revokeObjectURL = vi.fn();
    setup(video);
    return video;
  };

  it("reads a finite duration from the metadata", async () => {
    const { readVideoDuration } = await import("@/features/classifieds/photo-limits");
    const video = withVideo(() => undefined);
    const pending = readVideoDuration(new Blob());
    video.duration = 12.5;
    video.onloadedmetadata!();
    expect(await pending).toBe(12.5);
    vi.restoreAllMocks();
  });

  it("seeks to the end of a header-less WebM and reads the real duration", async () => {
    const { readVideoDuration } = await import("@/features/classifieds/photo-limits");
    const video = withVideo(() => undefined);
    const pending = readVideoDuration(new Blob());
    video.duration = Infinity;
    video.onloadedmetadata!();
    expect(video.currentTime).toBeGreaterThan(1e9);
    video.duration = 1.6;
    video.ondurationchange!();
    expect(await pending).toBe(1.6);
    vi.restoreAllMocks();
  });

  it("gives up (null) when the duration never becomes finite, and on errors", async () => {
    const { readVideoDuration } = await import("@/features/classifieds/photo-limits");
    const video = withVideo(() => undefined);
    const pending = readVideoDuration(new Blob(), 10);
    video.duration = Infinity;
    video.onloadedmetadata!();
    expect(await pending).toBeNull();
    const broken = withVideo(() => undefined);
    const failed = readVideoDuration(new Blob());
    broken.onerror!();
    expect(await failed).toBeNull();
    vi.restoreAllMocks();
  });
});
