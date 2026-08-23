import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BrowserMultiFormatReader } from '@zxing/browser'
import type { IScannerControls } from '@zxing/browser'
import { BarcodeFormat, DecodeHintType } from '@zxing/library'

interface CameraScannerProps {
  /** Called with each newly decoded barcode — the same handler the hardware-scanner/manual-entry path already uses (briefing 7.2). */
  onDecode: (code: string) => void
  onClose: () => void
}

// Barcode formats actually printed on physical media (briefing 6.: books/CDs/
// DVDs all carry an EAN) — restricting the decoder to these improves scan
// speed/accuracy and avoids misreads from unrelated formats like QR codes.
const POSSIBLE_FORMATS = [BarcodeFormat.EAN_13, BarcodeFormat.EAN_8, BarcodeFormat.UPC_A, BarcodeFormat.UPC_E]

/**
 * Minimum time before the same decoded code can trigger onDecode again. A
 * barcode held in front of the camera gets decoded many times a second by
 * the continuous scan loop below — without this, every one of those frames
 * would fire another submission for the same, still-visible item.
 */
const REPEAT_SUPPRESSION_MS = 3000

/**
 * GitHub issue #178: how long the decode loop waits before its next attempt
 * — after a frame with no barcode found, and after a successful decode
 * alike (zxing's BrowserCodeReader schedules the next attempt via
 * `delayBetweenScanAttempts`/`delayBetweenScanSuccess` respectively, both
 * defaulting to 500ms, i.e. only ~2 attempts/sec). Lowered here to increase
 * how often a genuinely good frame gets a chance at all — paired with
 * TRY_HARDER below, which makes each individual attempt itself more
 * thorough (and so a bit slower), rather than only faster-but-shallower
 * attempts on their own.
 */
const DECODE_ATTEMPT_INTERVAL_MS = 150

/**
 * Chrome/Android's `focusMode` track-constraint isn't part of the standard
 * `MediaTrackConstraintSet` TypeScript ships with (the DOM lib does list a
 * *read-back* `focusMode`/`torch`/`zoom` on `MediaTrackSettings`, just not
 * as constraints you can request) — a real, supported constraint (part of
 * Chrome's own Media Capture and Streams extensions) a device/browser that
 * doesn't recognize it simply ignores, not a guess about support. Typed
 * locally rather than widening the DOM lib globally, since it's this
 * component's own opt-in.
 */
interface ExtendedMediaTrackConstraintSet extends MediaTrackConstraintSet {
  focusMode?: 'continuous' | 'manual' | 'none' | 'single-shot'
}

/**
 * GitHub issue #177: a short synthesized confirmation beep, played on every
 * accepted decode — the "did that register?" feedback a dedicated hardware
 * barcode scanner already gives via its own built-in speaker, which this
 * camera-based path otherwise has none of at all. A plain oscillator via
 * the Web Audio API rather than a bundled sound file: a ~150ms tone needs
 * no real audio asset, and generating it in code avoids adding a network
 * request/bundle asset for something this small. `ctx` is created lazily on
 * the first successful decode (not at module/mount time — constructing an
 * AudioContext before any user gesture triggers a browser autoplay warning
 * on some browsers) and reused for every later beep in the same session
 * rather than recreated each time.
 */
function playScanBeep(ctx: AudioContext) {
  if (ctx.state === 'suspended') void ctx.resume()

  const oscillator = ctx.createOscillator()
  const gain = ctx.createGain()
  oscillator.type = 'sine'
  oscillator.frequency.value = 1046.5 // C6 — high enough to read as a clear "beep" over typical device speakers.
  // A short exponential decay reads as a crisp "beep" rather than an abrupt
  // click (jumping straight to 0 discontinuously) or a lingering tone.
  gain.gain.setValueAtTime(0.2, ctx.currentTime)
  gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.15)
  oscillator.connect(gain)
  gain.connect(ctx.destination)
  oscillator.start()
  oscillator.stop(ctx.currentTime + 0.15)
}

/**
 * Camera-based barcode scanning (briefing 7.2) — the third capture path
 * alongside the hardware scanner and manual entry, both of which already
 * funnel into CapturePage's scan handler; this component only decodes and
 * hands the result to that same handler via onDecode, exactly like a
 * hardware scanner "typing" a code would. Also plays a short confirmation
 * beep on every accepted decode (GitHub issue #177) — see playScanBeep()'s
 * own docblock.
 *
 * GitHub issue #178 improved real-world recognition rate several ways at
 * once, each independently useful: TRY_HARDER + a shorter decode-attempt
 * interval (more thorough *and* more frequent attempts), a higher
 * requested camera resolution, continuous autofocus, an optional torch
 * toggle for low light, and a visual guide overlay to help the user aim.
 * See each constant/effect/JSX block below for the specifics.
 */
export function CameraScanner({ onDecode, onClose }: CameraScannerProps) {
  const { t } = useTranslation()
  const videoRef = useRef<HTMLVideoElement>(null)
  const controlsRef = useRef<IScannerControls | null>(null)
  const lastDecodeRef = useRef<{ code: string; at: number } | null>(null)
  const audioContextRef = useRef<AudioContext | null>(null)
  // Kept in a ref rather than the effect's dependency array: onDecode is
  // typically a fresh closure every render on the caller's side, and
  // restarting the camera stream on every such render would be janky.
  const onDecodeRef = useRef(onDecode)
  onDecodeRef.current = onDecode
  const [accessError, setAccessError] = useState(false)
  // GitHub issue #178 — torchAvailable is only ever set true once the
  // resolved stream's track actually reports torch support (see the effect
  // below); the button itself is hidden entirely rather than shown
  // disabled when it isn't, since most laptop/desktop webcams (and many
  // phone front cameras) have no torch at all.
  const [torchAvailable, setTorchAvailable] = useState(false)
  const [torchOn, setTorchOn] = useState(false)

  useEffect(() => {
    const hints = new Map()
    hints.set(DecodeHintType.POSSIBLE_FORMATS, POSSIBLE_FORMATS)
    // GitHub issue #178 — see zxing's own OneDReader.doDecode(): without
    // this hint, only ~25 rows around the image's vertical center get
    // scanned (roughly 78% of the frame height) and a failed attempt is
    // never retried against a rotated copy of the frame; with it, the
    // *entire* frame is scanned top to bottom, and a failure is retried
    // against the frame rotated 90°. The single biggest lever here for a
    // barcode that's slightly off-center vertically or held sideways.
    hints.set(DecodeHintType.TRY_HARDER, true)
    const reader = new BrowserMultiFormatReader(hints, {
      delayBetweenScanAttempts: DECODE_ATTEMPT_INTERVAL_MS,
      delayBetweenScanSuccess: DECODE_ATTEMPT_INTERVAL_MS,
    })
    let cancelled = false
    const focusConstraint: ExtendedMediaTrackConstraintSet = { focusMode: 'continuous' }

    reader
      .decodeFromConstraints(
        {
          video: {
            facingMode: 'environment',
            // GitHub issue #178 — no resolution was requested before,
            // which left the browser free to negotiate a low default
            // stream (commonly 640x480 for `facingMode: 'environment'`
            // alone); a barcode's fine bar detail benefits from more
            // actually-captured pixels. "ideal", not "min"/exact, so a
            // device that can't reach this still gets its own best
            // available stream instead of getUserMedia failing outright.
            width: { ideal: 1920 },
            height: { ideal: 1080 },
            advanced: [focusConstraint],
          },
        },
        videoRef.current ?? undefined,
        (result) => {
          // A live video feed misses far more frames than it decodes (bad
          // angle, motion blur, nothing in view yet) — that's expected and
          // silent; only a successful decode is acted on here.
          if (!result) return

          const code = result.getText()
          const now = Date.now()
          const last = lastDecodeRef.current

          if (last && last.code === code && now - last.at < REPEAT_SUPPRESSION_MS) return

          lastDecodeRef.current = { code, at: now }

          try {
            audioContextRef.current ??= new AudioContext()
            playScanBeep(audioContextRef.current)
          } catch {
            // Audio is a nice-to-have here, not essential to capture itself
            // — a failure (no Web Audio support, a blocked autoplay
            // policy, ...) should never stop the decode from being handed
            // to onDecode below.
          }

          onDecodeRef.current(code)
        },
      )
      .then((controls) => {
        if (cancelled) {
          controls.stop()

          return
        }
        controlsRef.current = controls
        // GitHub issue #178 — @zxing/browser only ever populates
        // switchTorch once it has confirmed (via the resolved stream's own
        // track capabilities) that torch is actually supported; never
        // assumed available ahead of that.
        setTorchAvailable(controls.switchTorch !== undefined)
      })
      .catch(() => {
        if (!cancelled) setAccessError(true)
      })

    return () => {
      cancelled = true
      controlsRef.current?.stop()
      void audioContextRef.current?.close()
      audioContextRef.current = null
    }
  }, [])

  /** GitHub issue #178 — best-effort: a device/browser can still reject this at the exact moment of the call even though switchTorch was reported available (e.g. the track was reconfigured meanwhile), so a failure just leaves the toggle exactly as it was rather than surfacing an error for what's a convenience control, not something capture correctness depends on. */
  async function toggleTorch() {
    const nextOn = !torchOn
    try {
      await controlsRef.current?.switchTorch?.(nextOn)
      setTorchOn(nextOn)
    } catch {
      // See docblock above.
    }
  }

  return (
    <div className="camera-scanner">
      <div className="camera-scanner__viewport">
        <video ref={videoRef} className="camera-scanner__video" muted playsInline />
        {/* GitHub issue #178 — a purely visual aiming aid: darkens
            everything outside a centered guide box so the user holds the
            barcode there. Has no effect on the decode logic itself, which
            already scans the whole frame regardless (TRY_HARDER above). */}
        <div className="camera-scanner__guide" aria-hidden="true" />
      </div>
      <p>{t('capture.cameraHint')}</p>
      {torchAvailable && (
        <button type="button" onClick={() => void toggleTorch()}>
          {torchOn ? t('capture.cameraTorchOff') : t('capture.cameraTorchOn')}
        </button>
      )}
      {accessError && <p role="alert">{t('capture.cameraAccessError')}</p>}
      <button type="button" onClick={onClose}>
        {t('capture.cameraClose')}
      </button>
    </div>
  )
}
