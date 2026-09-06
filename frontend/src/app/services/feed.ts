import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, Subject } from 'rxjs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

if (typeof window !== 'undefined') {
  (window as any).Pusher = Pusher;
} else if (typeof globalThis !== 'undefined') {
  (globalThis as any).Pusher = Pusher;
}

@Injectable({
  providedIn: 'root'
})
export class FeedService {
  private echo: Echo<'reverb'> | null = null;
  private feedUpdates = new Subject<any[]>();

  constructor(private http: HttpClient) {
    try {
      // Configure Laravel Echo to connect to Reverb
      this.echo = new Echo({
        broadcaster: 'reverb',
        key: 'capital_raise_reverb_key',
        wsHost: '127.0.0.1',
        wsPort: 8080,
        forceTLS: false,
        enabledTransports: ['ws', 'wss'],
        Pusher: Pusher,
      });

      this.listenToFeed();
    } catch (err) {
      console.warn('Laravel Echo / Reverb initialization error:', err);
    }
  }

  // Fetch initial history from Laravel REST endpoint
  getInitialFeed(): Observable<any[]> {
    return this.http.get<any[]>('http://localhost:8000/api/edgar-feed');
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
