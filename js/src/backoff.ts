export interface BackoffOptions {
  /** First retry delay in ms. */
  initialDelay?: number;
  /** Upper bound for a single delay in ms. */
  maxDelay?: number;
  /** Growth factor per attempt. */
  factor?: number;
  /** Share of the delay that is randomised, 0..1. Spreads reconnect storms after a server restart. */
  jitter?: number;
}

/** Delay before reconnect attempt `attempt` (0-based): exponential, capped, with proportional jitter. */
export function backoffDelay(attempt: number, options: BackoffOptions = {}, random: () => number = Math.random): number {
  const initial = options.initialDelay ?? 500;
  const max = options.maxDelay ?? 30_000;
  const factor = options.factor ?? 2;
  const jitter = Math.min(1, Math.max(0, options.jitter ?? 0.5));
  const base = Math.min(max, initial * Math.pow(factor, attempt));
  return Math.round(base * (1 - jitter) + base * jitter * random());
}
