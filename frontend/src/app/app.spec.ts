import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting, HttpTestingController } from '@angular/common/http/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { App } from './app';
import { FeedService } from './services/feed';

describe('App', () => {
  let httpTesting: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideZonelessChangeDetection(),
        provideHttpClient(),
        provideHttpClientTesting(),
      ]
    })
      .compileComponents();

    httpTesting = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    httpTesting.verify();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const req = httpTesting.expectOne('http://localhost:8000/api/edgar-feed');
    req.flush([]);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  it('should render title', async () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const req = httpTesting.expectOne('http://localhost:8000/api/edgar-feed');
    req.flush([]);
    await fixture.whenStable();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('h1')?.textContent).toContain('SEC EDGAR Real-Time News Feed');
  });

  it('should render feed items from backend', async () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    const req = httpTesting.expectOne('http://localhost:8000/api/edgar-feed');
    req.flush([
      {
        id: '1',
        title: 'Apple Inc. - Form 8-K',
        updated: 1725480000,
        link: 'https://www.sec.gov/filing/1'
      }
    ]);
    await fixture.whenStable();
    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Apple Inc. - Form 8-K');
    const link = compiled.querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://www.sec.gov/filing/1');
  });

  it('should update feed when live stream receives new filings', async () => {
    const fixture = TestBed.createComponent(App);
    const feedService = TestBed.inject(FeedService);
    fixture.detectChanges();
    const req = httpTesting.expectOne('http://localhost:8000/api/edgar-feed');
    req.flush([
      {
        id: '1',
        title: 'Initial Filing',
        updated: 1725480000,
        link: 'https://www.sec.gov/filing/1'
      }
    ]);
    await fixture.whenStable();

    (feedService as any).feedUpdates.next([
      {
        id: '2',
        title: 'Live Filing Newly Arrived',
        updated: 1725480050,
        link: 'https://www.sec.gov/filing/2'
      }
    ]);
    await fixture.whenStable();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.textContent).toContain('Live Filing Newly Arrived');
    expect(compiled.textContent).toContain('Initial Filing');
  });
});
