import { Component, OnInit, signal } from '@angular/core';
import { DatePipe } from '@angular/common';
import { FeedService } from './services/feed';

@Component({
  selector: 'app-root',
  imports: [DatePipe],
  template: `
    <div style="padding: 20px; font-family: sans-serif;">
      <h1>🏛️ SEC EDGAR Real-Time News Feed</h1>
      @for (item of feed(); track item.id || $index) {
        <div style="border-bottom: 1px solid #ccc; padding: 10px 0;">
          <h3>
            <a [href]="item.link" target="_blank">{{ item.title }}</a>
          </h3>
          <small>Filed at: {{ item.updated * 1000 | date: 'medium' }}</small>
        </div>
      }
    </div>
  `,
})
export class App implements OnInit {
  feed = signal<any[]>([]);

  constructor(private feedService: FeedService) {}

  ngOnInit() {
    // 1. Get baseline history from Redis
    this.feedService.getInitialFeed().subscribe({
      next: (initialData) => {
        this.feed.set(initialData || []);
      },
      error: (err) => {
        console.error('Error fetching initial feed:', err);
      }
    });

    // 2. Prepend live stream chunks as they arrive
    this.feedService.getLiveUpdates().subscribe({
      next: (newFilings) => {
        if (newFilings && newFilings.length > 0) {
          this.feed.update((feed) => [...newFilings, ...feed]);
        }
      },
      error: (err) => {
        console.error('Error in live updates:', err);
      }
    });
  }
}

export { App as AppComponent };
