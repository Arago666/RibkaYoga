import { normalizeMetronome, type MetronomeBeat, type MetronomeSettings } from './metronome';

export interface MetronomeState {
  running: boolean;
  beat: number;
  subdivision: number;
  bar: number;
  elapsed: number;
  remaining: number | null;
  countingIn: boolean;
  bpm: number;
  reason: 'paused' | 'limit' | 'hidden' | null;
}
interface Pulse {
  time: number;
  index: number;
  bpm: number;
}
/** Audio-clock scheduling keeps clicks independent of DOM rendering. */
export class MetronomeEngine {
  private settings = normalizeMetronome();
  private context: AudioContext | null = null;
  private buffers: Partial<Record<'click' | 'accent', AudioBuffer>> = {};
  private bufferJob: Promise<void> | null = null;
  private sources = new Map<AudioScheduledSourceNode, AudioNode[]>();
  private timer: ReturnType<typeof setInterval> | null = null;
  private queue: Pulse[] = [];
  private running = false;
  private desiredRunning = false;
  private disposed = false;
  private generation = 0;
  private nextTime = 0;
  private nextIndex = 0;
  private lastIndex = -1;
  private delay = 0.05;
  private elapsedBase = 0;
  private mainStart: number | null = null;
  private trackerSecondsOffset = 0;
  private trackerBarsOffset = 0;
  private bpm = 120;
  private scheduledBpm = 120;
  private beat = -1;
  private subdivision = 0;
  private completedBars = 0;
  private countingIn = false;
  private reason: MetronomeState['reason'] = null;
  private emitAt = 0;
  private visibility = () => {
    if (document.hidden && this.running) {
      this.stop();
      this.reason = 'hidden';
      this.emit();
    }
  };
  constructor(private changed: (state: MetronomeState) => void = () => {}) {
    document.addEventListener('visibilitychange', this.visibility);
  }
  get state(): MetronomeState {
    const elapsed = this.elapsed();
    return {
      running: this.running,
      beat: this.beat,
      subdivision: this.subdivision,
      bar:
        Math.max(0, this.completedBars - this.trackerBarsOffset) +
        (this.settings.tracker.start_from_one && !this.settings.tracker.countdown ? 1 : 0),
      elapsed: Math.max(0, elapsed - this.trackerSecondsOffset),
      remaining:
        this.settings.tracker.mode === 'timer' && this.settings.tracker.countdown
          ? Math.max(0, this.settings.tracker.seconds - (elapsed - this.trackerSecondsOffset))
          : null,
      countingIn: this.running && this.countingIn,
      bpm: this.bpm,
      reason: this.reason,
    };
  }
  private elapsed(now = this.context?.currentTime ?? 0): number {
    return (
      this.elapsedBase + (this.running && this.mainStart !== null ? Math.max(0, now - this.mainStart) : 0)
    );
  }
  private emit() {
    this.changed(this.state);
  }
  get active(): boolean {
    return this.running || this.desiredRunning;
  }
  private reset() {
    this.elapsedBase = 0;
    this.mainStart = null;
    this.beat = -1;
    this.subdivision = 0;
    this.completedBars = 0;
    this.trackerSecondsOffset = 0;
    this.trackerBarsOffset = 0;
    this.bpm = this.settings.bpm;
    this.scheduledBpm = this.settings.bpm;
    this.nextIndex = -this.settings.count_in_bars * this.settings.beats * this.settings.subdivision;
    this.lastIndex = this.nextIndex - 1;
    this.countingIn = this.nextIndex < 0;
    this.delay = 0.05;
  }
  private async loadBuffers() {
    if (!this.context || this.bufferJob) return this.bufferJob;
    const context = this.context;
    this.bufferJob = Promise.all(
      (['click', 'accent'] as const).map(async (name) => {
        try {
          const response = await fetch(`/audio/metronome/${name}.wav`);
          if (!response.ok) return;
          const data = await response.arrayBuffer();
          if (!this.disposed) this.buffers[name] = await context.decodeAudioData(data);
        } catch {
          // The synthesised click remains available when a sample cannot load.
        }
      }),
    ).then(() => {});
    return this.bufferJob;
  }
  async unlock(): Promise<void> {
    if (this.disposed) return;
    this.context ??= new AudioContext();
    await this.context.resume();
    await this.loadBuffers();
  }
  async start(settings: MetronomeSettings, resume = false): Promise<void> {
    if (this.disposed) return;
    this.stop();
    const generation = ++this.generation;
    this.desiredRunning = true;
    this.settings = normalizeMetronome(settings);
    if (!resume) this.reset();
    try {
      await this.unlock();
    } catch (failure) {
      if (generation === this.generation) this.desiredRunning = false;
      throw failure;
    }
    if (!this.context) return;
    if (this.disposed || generation !== this.generation) {
      return;
    }
    if (document.hidden) {
      this.desiredRunning = false;
      this.reason = 'hidden';
      void this.context.suspend().catch(() => {});
      this.emit();
      return;
    }
    this.running = true;
    this.reason = null;
    this.queue = [];
    this.nextTime = this.context.currentTime + Math.max(0.025, this.delay);
    this.nextIndex = this.lastIndex + 1;
    this.scheduledBpm = this.bpm;
    if (!this.countingIn) this.mainStart = this.context.currentTime;
    this.emitAt = 0;
    this.loop();
    this.timer = setInterval(() => this.loop(), 25);
  }
  update(settings: MetronomeSettings) {
    const wasRunning = this.active;
    const old = this.settings;
    this.stop();
    this.settings = normalizeMetronome(settings);
    const structureChanged =
      old.beats !== this.settings.beats || old.subdivision !== this.settings.subdivision;
    if (structureChanged) this.reset();
    else if (old.bpm !== this.settings.bpm) this.bpm = this.settings.bpm;
    this.emit();
    if (wasRunning) void this.start(this.settings, true).catch(() => this.stop());
  }
  stop(reset = false) {
    ++this.generation;
    this.desiredRunning = false;
    const now = this.context?.currentTime ?? 0;
    if (this.running) {
      this.consume(now);
      this.elapsedBase = this.elapsed(now);
      const queued = this.queue.find((pulse) => pulse.time > now);
      this.delay = Math.max(0.025, (queued?.time ?? this.nextTime) - now);
      this.nextIndex = this.lastIndex + 1;
    }
    this.running = false;
    this.mainStart = null;
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
    this.queue = [];
    for (const [source, nodes] of this.sources) {
      try {
        source.stop();
      } catch {
        /* Already ended. */
      }
      source.disconnect();
      for (const node of nodes) node.disconnect();
    }
    this.sources.clear();
    if (this.context?.state === 'running') void this.context.suspend().catch(() => {});
    if (reset) this.reset();
    this.reason = 'paused';
    this.emit();
  }
  dispose() {
    if (this.disposed) return;
    this.stop(true);
    this.disposed = true;
    document.removeEventListener('visibilitychange', this.visibility);
    void this.context?.close().catch(() => {});
    this.context = null;
    this.buffers = {};
  }
  private consume(now: number) {
    while (this.queue.length && this.queue[0].time <= now) {
      const pulse = this.queue.shift()!;
      this.lastIndex = pulse.index;
      this.bpm = pulse.bpm;
      const count = this.settings.beats * this.settings.subdivision;
      const inBar = ((pulse.index % count) + count) % count;
      this.beat = Math.floor(inBar / this.settings.subdivision);
      this.subdivision = inBar % this.settings.subdivision;
      this.countingIn = pulse.index < 0;
      if (pulse.index >= 0) {
        this.completedBars = Math.floor(pulse.index / count);
        if (this.mainStart === null) this.mainStart = pulse.time;
      }
    }
  }
  private limitReached(now: number): boolean {
    const tracker = this.settings.tracker;
    if (this.countingIn || tracker.mode === 'off') return false;
    const elapsed = this.elapsed(now) - this.trackerSecondsOffset;
    const bars = this.completedBars - this.trackerBarsOffset;
    const reached = tracker.mode === 'timer' ? elapsed >= tracker.seconds : bars >= tracker.bars;
    if (!reached) return false;
    if (tracker.stop_at_end) {
      this.stop(tracker.reset_at_end);
      this.reason = 'limit';
      this.emit();
      return true;
    }
    if (tracker.reset_at_end) {
      if (tracker.mode === 'timer')
        this.trackerSecondsOffset += tracker.seconds * Math.floor(elapsed / tracker.seconds);
      else this.trackerBarsOffset += tracker.bars * Math.floor(bars / tracker.bars);
    }
    return false;
  }
  private loop() {
    if (!this.running || !this.context) return;
    const now = this.context.currentTime;
    if (this.context.state !== 'running') {
      this.stop();
      return;
    }
    this.consume(now);
    if (this.limitReached(now)) return;
    // A suspended event loop must not produce a burst of missed clicks.
    if (this.nextTime < now - 0.2) {
      this.stop();
      return;
    }
    const count = this.settings.beats * this.settings.subdivision;
    while (this.nextTime < now + 0.1) {
      const index = this.nextIndex;
      const inBar = ((index % count) + count) % count;
      const beat = Math.floor(inBar / this.settings.subdivision);
      const subdivision = inBar % this.settings.subdivision;
      const bars = index >= 0 ? Math.floor(index / count) : -1;
      if (
        index > 0 &&
        inBar === 0 &&
        this.settings.automator.enabled &&
        bars % this.settings.automator.every_bars === 0
      ) {
        const target = this.settings.automator.target_bpm;
        this.scheduledBpm +=
          Math.sign(target - this.scheduledBpm) *
          Math.min(this.settings.automator.step_bpm, Math.abs(target - this.scheduledBpm));
      }
      const limitBar =
        index >= 0 &&
        this.settings.tracker.mode === 'bars' &&
        this.settings.tracker.stop_at_end &&
        bars - this.trackerBarsOffset >= this.settings.tracker.bars;
      if (!limitBar) {
        const kind = index < 0 ? (beat === 0 ? 'accent' : 'normal') : this.settings.pattern[beat];
        this.sound(this.nextTime, kind, subdivision > 0);
      }
      this.queue.push({ time: this.nextTime, index, bpm: this.scheduledBpm });
      this.nextIndex++;
      this.nextTime += 60 / this.scheduledBpm / this.settings.subdivision;
    }
    if (now - this.emitAt >= 0.06) {
      this.emitAt = now;
      this.emit();
    }
  }
  private track(source: AudioScheduledSourceNode, nodes: AudioNode[]) {
    this.sources.set(source, nodes);
    source.onended = () => {
      this.sources.delete(source);
      source.disconnect();
      for (const node of nodes) node.disconnect();
    };
  }
  private sound(time: number, kind: MetronomeBeat, subdivision: boolean) {
    const context = this.context;
    if (!context || kind === 'mute' || this.settings.volume <= 0) return;
    const volume = (this.settings.volume / 100) ** 1.5;
    const accent = kind === 'accent' || kind === 'strong';
    const strength = subdivision ? 0.35 : kind === 'strong' ? 1 : kind === 'accent' ? 0.85 : 0.65;
    const gain = context.createGain();
    gain.connect(context.destination);
    if (this.settings.sound === 'click' && this.buffers[accent ? 'accent' : 'click']) {
      const source = context.createBufferSource();
      source.buffer = this.buffers[accent ? 'accent' : 'click']!;
      source.playbackRate.value = subdivision ? 1.35 : kind === 'strong' ? 0.84 : 1;
      gain.gain.setValueAtTime(volume * strength * 0.7, time);
      source.connect(gain);
      this.track(source, [gain]);
      source.start(time);
      return;
    }
    const source = context.createOscillator();
    const sound = this.settings.sound;
    source.type = sound === 'wood' ? 'triangle' : sound === 'click' ? 'square' : 'sine';
    const base = sound === 'wood' ? 680 : sound === 'beep' ? 880 : sound === 'bell' ? 1320 : 1200;
    source.frequency.setValueAtTime(base * (accent ? 1.5 : 1) * (subdivision ? 0.8 : 1), time);
    const duration = sound === 'bell' ? 0.18 : sound === 'wood' ? 0.07 : 0.045;
    if (sound === 'wood') source.frequency.exponentialRampToValueAtTime(base * 0.7, time + duration);
    gain.gain.setValueAtTime(0.0001, time);
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0001, volume * strength * 0.16), time + 0.002);
    gain.gain.exponentialRampToValueAtTime(0.0001, time + duration);
    source.connect(gain);
    this.track(source, [gain]);
    source.start(time);
    source.stop(time + duration + 0.01);
  }
}
