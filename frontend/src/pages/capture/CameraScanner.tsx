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
 * Camera-based barcode scanning (briefing 7.2) — the third capture path
 * alongside the hardware scanner and manual entry, both of which already
 * funnel into CapturePage's scan handler; this component only decodes and
 * hands the result to that same handler via onDecode, exactly like a
 * hardware scanner "typing" a code would.
 */
export function CameraScanner({ onDecode, onClose }: CameraScannerProps) {
  const { t } = useTranslation()
  const videoRef = useRef<HTMLVideoElement>(null)
  const controlsRef = useRef<IScannerControls | null>(null)
  const lastDecodeRef = useRef<{ code: string; at: number } | null>(null)
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
