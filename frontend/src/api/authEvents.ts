/**
 * Decouples apiClient's response interceptor (outside the React tree, can't
 * call useNavigate()/useAuth() directly) from AuthContext, which is what
 * actually needs to react to a 401/403 by clearing the session — see
 * client.ts's interceptor and AuthContext's subscription. A plain listener
 * set rather than a full event-emitter dependency, since there's exactly
 * one event type and at most one real subscriber (AuthProvider).
 */
export type SessionEndReason = 'session_expired' | 'account_deactivated'

type Listener = (reason: SessionEndReason) => void

const listeners = new Set<Listener>()

/** Returns an unsubscribe function, for use in a useEffect cleanup. */
export function onSessionEnded(listener: Listener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function notifySessionEnded(reason: SessionEndReason): void {
  listeners.forEach((listener) => listener(reason))
}
