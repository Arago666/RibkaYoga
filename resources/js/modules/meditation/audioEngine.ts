import type { MeditationConfig, Signal } from './types';
import { soundManifest } from './soundManifest';

/** Audio is started only by a user gesture, never by page load. */
export class MeditationAudio {
  private context: AudioContext | null = null;
  private master: GainNode | null = null;
  private sources = new Set<AudioScheduledSourceNode>();
  private nodes = new Set<AudioNode>();
  private effects = new Set<ReturnType<typeof setInterval>>();
  private personal: HTMLAudioElement | null = null;
  private personalPosition = 0;
  private personalId: string | null = null;
  private personalFinished = false;
  private tone: OscillatorNode | null = null;
  private muted = false;

  async unlock(): Promise<void> {
    if (!this.context || this.context.state === 'closed') {
      this.context = new AudioContext();
      this.master = this.context.createGain();
      this.master.gain.value = this.muted ? 0 : 0.55;
      const limiter = this.context.createDynamicsCompressor();
      limiter.threshold.value = -12;
      limiter.ratio.value = 10;
      this.master.connect(limiter).connect(this.context.destination);
    }
    if (this.context.state !== 'running') await this.context.resume();
  }

  setMuted(value: boolean): void {
    this.muted = value;
    if (this.master && this.context)
      this.master.gain.setTargetAtTime(value ? 0 : 0.55, this.context.currentTime, 0.03);
  }

  async start(
    config: MeditationConfig,
    elapsedSeconds: number,
    audioUrl: string | null,
    ended: () => void,
    failed: (message: string) => void,
  ): Promise<void> {
    await this.unlock();
    this.pause();
    this.ambience(config.ambience, config.ambience_volume);
    if (config.tone_enabled && elapsedSeconds < config.tone_seconds) {
      const context = this.context!;
      const gain = this.gain(Math.min(0.2, config.tone_volume) * 0.4);
      const oscillator = context.createOscillator();
      oscillator.type = 'sine';
      oscillator.frequency.value = config.tone_frequency;
      oscillator.connect(gain);
      this.track(oscillator, [gain]);
      oscillator.start();
      oscillator.stop(context.currentTime + config.tone_seconds - elapsedSeconds);
      this.tone = oscillator;
    }
    if (
      config.audio_asset_id &&
      audioUrl &&
      !(this.personalFinished && this.personalId === config.audio_asset_id)
    ) {
      if (this.personalId !== config.audio_asset_id) {
        this.personalPosition = 0;
        this.personalFinished = false;
      }
      this.personalId = config.audio_asset_id;
      const audio = new Audio(audioUrl);
      this.personal = audio;
      audio.preload = 'metadata';
      audio.loop = config.audio_repeat;
      const source = this.context!.createMediaElementSource(audio);
      const gain = this.gain(config.audio_volume * 0.55);
      source.connect(gain);
      this.nodes.add(source);
      audio.addEventListener(
        'loadedmetadata',
        () => {
          if (this.personal === audio && this.personalPosition < audio.duration)
            audio.currentTime = this.personalPosition;
        },
        { once: true },
      );
      audio.addEventListener('ended', () => {
        if (this.personal === audio) {
          this.personalFinished = true;
          if (config.audio_end === 'finish') ended();
        }
      });
      audio.addEventListener(
        'error',
        () => {
          if (this.personal === audio)
            failed(
              'Личное аудио не удалось воспроизвести. Таймер продолжает работать; можно поставить его на паузу и проверить файл.',
            );
        },
        { once: true },
      );
      try {
        await audio.play();
      } catch {
        throw new Error(
          'Браузер не включил личное аудио. Нажмите «Включить звук»; таймер остаётся под вашим управлением.',
        );
      }
    }
  }

  async preview(signal: Signal, volume: number): Promise<void> {
    await this.unlock();
    this.signal(signal, volume);
  }

  signal(signal: Signal, volume: number): void {
    if (signal === 'none' || !this.context || !this.master || volume <= 0) return;
    const context = this.context;
    const instrument = soundManifest.signals[signal];
    instrument.ratios.forEach((ratio, index) => {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      const amplitude = (Math.min(1, volume) * 0.19) / (index + 1);
      oscillator.frequency.value = instrument.fundamental * ratio;
      oscillator.type = 'sine';
      gain.gain.setValueAtTime(0, context.currentTime);
      gain.gain.linearRampToValueAtTime(amplitude, context.currentTime + 0.018);
      gain.gain.exponentialRampToValueAtTime(0.00001, context.currentTime + instrument.duration);
      oscillator.connect(gain).connect(this.master!);
      this.track(oscillator, [gain]);
      oscillator.start();
      oscillator.stop(context.currentTime + instrument.duration + 0.03);
    });
  }

  pause(): void {
    this.effects.forEach(clearInterval);
    this.effects.clear();
    const oldSources = [...this.sources],
      oldNodes = [...this.nodes];
    const now = this.context?.currentTime ?? 0;
    for (const node of oldNodes)
      if (node instanceof GainNode) {
        node.gain.cancelScheduledValues(now);
        node.gain.setTargetAtTime(0.00001, now, 0.02);
      }
    for (const source of oldSources) {
      try {
        source.stop(now + 0.09);
      } catch {
        /* Already finished. */
      }
    }
    this.sources.clear();
    this.nodes.clear();
    setTimeout(() => {
      oldSources.forEach((source) => source.disconnect());
      oldNodes.forEach((node) => node.disconnect());
    }, 110);
    this.tone = null;
    if (this.personal) {
      const oldAudio = this.personal;
      this.personalPosition = oldAudio.currentTime;
      setTimeout(() => {
        oldAudio.pause();
        oldAudio.removeAttribute('src');
        oldAudio.load();
      }, 100);
      this.personal = null;
    }
  }

  stop(): void {
    this.pause();
    this.personalPosition = 0;
    this.personalId = null;
    this.personalFinished = false;
  }

  async dispose(): Promise<void> {
    this.stop();
    await this.context?.close();
    this.context = null;
    this.master = null;
  }

  private gain(value: number): GainNode {
    const gain = this.context!.createGain();
    gain.gain.setValueAtTime(0, this.context!.currentTime);
    gain.gain.linearRampToValueAtTime(Math.max(0, Math.min(0.55, value)), this.context!.currentTime + 0.15);
    gain.connect(this.master!);
    this.nodes.add(gain);
    return gain;
  }

  private track(source: AudioScheduledSourceNode, related: AudioNode[] = []): void {
    this.sources.add(source);
    related.forEach((node) => this.nodes.add(node));
    source.onended = () => {
      this.sources.delete(source);
      source.disconnect();
      related.forEach((node) => {
        node.disconnect();
        this.nodes.delete(node);
      });
    };
  }

  private ambience(kind: MeditationConfig['ambience'], volume: number): void {
    if (kind === 'none' || volume <= 0) return;
    const context = this.context!;
    const duration = kind === 'waves' ? 16 : 8;
    const length = Math.floor(context.sampleRate * duration);
    const buffer = context.createBuffer(1, length, context.sampleRate);
    const samples = buffer.getChannelData(0);
    let brown = 0;
    for (let i = 0; i < length; i++) {
      const random = Math.random() * 2 - 1;
      brown = (brown + random * 0.02) / 1.02;
      const phase = (i / length) * Math.PI * 4;
      samples[i] =
        kind === 'white'
          ? random * 0.35
          : kind === 'rain'
            ? random * 0.3
            : brown * (kind === 'waves' ? 0.3 + (Math.sin(phase) + 1) * 0.7 : 0.7);
    }
    // Smooth the seam using a short cosine blend; both ends meet continuously.
    const blend = Math.min(2048, length / 4);
    const seam = (samples[0]! + samples[length - 1]!) / 2;
    for (let i = 0; i < blend; i++) {
      const t = (1 - Math.cos((i / blend) * Math.PI)) / 2;
      samples[i] = samples[i]! * t + seam * (1 - t);
      samples[length - 1 - i] = samples[length - 1 - i]! * t + seam * (1 - t);
    }
    const source = context.createBufferSource();
    source.buffer = buffer;
    source.loop = true;
    const filter = context.createBiquadFilter();
    filter.type = kind === 'rain' ? 'highpass' : 'lowpass';
    filter.frequency.value = kind === 'white' ? 18000 : kind === 'rain' ? 900 : 1500;
    const gain = this.gain(volume * 0.45);
    source.connect(filter).connect(gain);
    this.track(source, [filter, gain]);
    source.start();
    if (kind === 'forest' || kind === 'rain') {
      const effect = () => {
        if (this.context?.state !== 'running') return;
        const now = context.currentTime;
        const oscillator = context.createOscillator();
        const voice = context.createGain();
        const duration = kind === 'forest' ? 0.25 : 0.055;
        const frequency = kind === 'forest' ? 1600 + Math.random() * 1800 : 500 + Math.random() * 1500;
        oscillator.frequency.setValueAtTime(frequency, now);
        oscillator.frequency.exponentialRampToValueAtTime(
          frequency * (kind === 'forest' ? 1.55 : 0.5),
          now + duration,
        );
        voice.gain.setValueAtTime(0.00001, now);
        voice.gain.exponentialRampToValueAtTime(volume * (kind === 'forest' ? 0.025 : 0.008), now + 0.015);
        voice.gain.exponentialRampToValueAtTime(0.00001, now + duration);
        oscillator.connect(voice).connect(this.master!);
        this.track(oscillator, [voice]);
        oscillator.start();
        oscillator.stop(now + duration + 0.01);
      };
      effect();
      this.effects.add(setInterval(effect, kind === 'forest' ? 1900 : 220));
    }
  }
}
