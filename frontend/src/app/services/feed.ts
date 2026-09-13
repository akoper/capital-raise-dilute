import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, Subject } from 'rxjs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { environment } from '../../environments/environment';

if (typeof window !== 'undefined') {
  (window as any).Pusher = Pusher;
} else if (typeof globalThis !== 'undefined') {
  (globalThis as any).Pusher = Pusher;
}

interface ReverbConfig {
  key: string;
  host: string;
  port: number;
  scheme?: string;
  useTLS?: boolean;
}

@Injectable({
  providedIn: 'root'
})
export class FeedService {
  private echo: Echo<'reverb'> | null = null;
  private feedUpdates = new Subject<any[]>();
  private currentConfig: ReverbConfig | null = null;

  constructor(private http: HttpClient) {
    const initialConfig = this.resolveReverbConfig();
    this.initEcho(initialConfig);

    // Fetch dynamic WebSocket configuration from backend API if available
    this.fetchRemoteConfig();
  }

  private resolveApiBase(): string {
    if (typeof window !== 'undefined') {
      if ((window as any).__API_URL__) {
        return (window as any).__API_URL__;
      }
      const host = window.location.hostname;
      if (host.includes('edgar-frontend')) {
        return `${window.location.protocol}//${host.replace('edgar-frontend', 'edgar-api')}`;
      }
      if (environment.apiUrl) {
        return environment.apiUrl;
      }
      if (!['localhost', '127.0.0.1', '::1'].includes(host)) {
        return window.location.origin;
      }
    }
    return environment.apiUrl || 'http://localhost:8001';
  }

  private resolveReverbConfig(): ReverbConfig {
    if (typeof window !== 'undefined') {
      const winConfig = (window as any).__REVERB_CONFIG__;
      if (winConfig && winConfig.host) {
        const isSecure = winConfig.scheme === 'https' || (winConfig.useTLS ?? false);
        return {
          key: winConfig.key || environment.reverbKey,
          host: winConfig.host,
          port: Number(winConfig.port) || (isSecure ? 443 : 8080),
          scheme: winConfig.scheme || (isSecure ? 'https' : 'http'),
          useTLS: winConfig.useTLS ?? isSecure
        };
      }

      const host = window.location.hostname;
      if (host.includes('edgar-frontend')) {
        return {
          key: environment.reverbKey,
          host: host.replace('edgar-frontend', 'edgar-reverb'),
          port: 443,
          scheme: 'https',
          useTLS: true
        };
      }

      if (host.includes('edgar-api')) {
        return {
          key: environment.reverbKey,
          host: host.replace('edgar-api', 'edgar-reverb'),
          port: 443,
          scheme: 'https',
          useTLS: true
        };
      }

      if (environment.reverbHost && !['localhost', '127.0.0.1', '::1'].includes(environment.reverbHost)) {
        const isLocal = ['localhost', '127.0.0.1', '::1'].includes(host);
        const useTLS = environment.reverbForceTLS ?? (!isLocal && window.location.protocol === 'https:');
        return {
          key: environment.reverbKey,
          host: environment.reverbHost,
          port: environment.reverbPort || (useTLS ? 443 : 8080),
          scheme: useTLS ? 'https' : 'http',
          useTLS: useTLS
        };
      }
    }

    const host = environment.reverbHost || (typeof window !== 'undefined' ? window.location.hostname : '127.0.0.1');
    const isLocal = ['localhost', '127.0.0.1', '::1'].includes(host);
    const isHttps = typeof window !== 'undefined' && window.location.protocol === 'https:' && !isLocal;

    return {
      key: environment.reverbKey,
      host: host,
      port: environment.reverbPort || (isHttps ? 443 : 8080),
      scheme: isHttps ? 'https' : 'http',
      useTLS: environment.reverbForceTLS ?? isHttps
    };
  }

  private initEcho(config: ReverbConfig) {
    try {
      if (this.echo) {
        this.echo.disconnect();
        this.echo = null;
      }

      this.currentConfig = config;
      const isTLS = config.useTLS ?? (config.scheme === 'https');

      this.echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: isTLS,
        enabledTransports: ['ws', 'wss'],
        Pusher: Pusher,
      });

      this.listenToFeed();
    } catch (err) {
      console.warn('Laravel Echo / Reverb initialization error:', err);
    }
  }

  private fetchRemoteConfig() {
    const apiEndpoint = this.getEndpoint('/api/ws-config');
    this.http.get<ReverbConfig>(apiEndpoint).subscribe({
      next: (cfg) => {
        if (cfg && cfg.host) {
          const isTLS = cfg.useTLS ?? (cfg.scheme === 'https');
          const resolvedCfg: ReverbConfig = {
            key: cfg.key || this.currentConfig?.key || environment.reverbKey,
            host: cfg.host,
            port: Number(cfg.port) || (isTLS ? 443 : 8080),
            scheme: cfg.scheme || (isTLS ? 'https' : 'http'),
            useTLS: isTLS,
          };

          const needsReinit = !this.currentConfig ||
            this.currentConfig.host !== resolvedCfg.host ||
            this.currentConfig.port !== resolvedCfg.port ||
            this.currentConfig.key !== resolvedCfg.key ||
            this.currentConfig.useTLS !== resolvedCfg.useTLS;

          if (needsReinit) {
            this.initEcho(resolvedCfg);
          }
        }
      },
      error: () => {
        // Silently keep initial resolved config if endpoint is unavailable
      }
    });
  }

  private getEndpoint(path: string): string {
    const base = this.resolveApiBase().replace(/\/+$/, '');
    const cleanPath = path.startsWith('/') ? path : `/${path}`;
    return base ? `${base}${cleanPath}` : cleanPath;
  }

  // Fetch initial history from Laravel REST endpoint
  getInitialFeed(): Observable<any[]> {
    const endpoint = this.getEndpoint('/api/edgar-feed');
    return this.http.get<any[]>(endpoint);
  }

  // Listen to WebSocket broadcasts
  private listenToFeed() {
    if (!this.echo) return;

    this.echo.channel('edgar-stream')
      .listen('.new-filings', (data: any) => {
        const filings = Array.isArray(data) ? data : (data?.filings || []);
        if (filings.length > 0) {
          this.feedUpdates.next(filings);
        }
      });
  }

  getLiveUpdates(): Observable<any[]> {
    return this.feedUpdates.asObservable();
  }
}
