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

  useEffect(() => {
    const hints = new Map()
    hints.set(DecodeHintType.POSSIBLE_FORMATS, POSSIBLE_FORMATS)
    const reader = new BrowserMultiFormatReader(hints)
    let cancelled = false

    reader
      .decodeFromConstraints({ video: { facingMode: 'environment' } }, videoRef.current ?? undefined, (result) => {
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
          // Audio is a nice-to-have here, not essential to capture itself —
          // a failure (no Web Audio support, a blocked autoplay policy, ...)
          // should never stop the decode from being handed to onDecode below.
        }

        onDecodeRef.current(code)
      })
      .then((controls) => {
        if (cancelled) {
          controls.stop()

          return
        }
        controlsRef.current = controls
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

  return (
    <div className="camera-scanner">
      <video ref={videoRef} className="camera-scanner__video" muted playsInline />
      <p>{t('capture.cameraHint')}</p>
      {accessError && <p role="alert">{t('capture.cameraAccessError')}</p>}
      <button type="button" onClick={onClose}>
        {t('capture.cameraClose')}
      </button>
    </div>
  )
}
