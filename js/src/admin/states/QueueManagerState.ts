import app from 'flarum/admin/app';

export interface JobDetail {
  label: string;
  value: string;
}

export interface FailedJobRow {
  id: number;
  uuid: string | null;
  connection: string | null;
  queue: string;
  display_name: string;
  command_class: string;
  description: string;
  max_tries: number | null;
  timeout: number | null;
  backoff: number | null;
  details: JobDetail[];
  payload_summary: string;
  exception_head: string;
  failed_at: string | null;
}

export interface QueueStats {
  pending: number;
  failed: number;
  queues: { queue: string; count: number }[];
}

export interface ListResponse {
  data: FailedJobRow[];
  total: number;
  limit: number;
  offset: number;
  hasMore: boolean;
  stats: QueueStats;
  prefix: string;
  tablesExist: boolean;
  tables: { failed: string; jobs: string };
}

export interface DetailResponse {
  data: FailedJobRow & { payload: string; exception: string };
}

export default class QueueManagerState {
  loading = false;
  rows: FailedJobRow[] = [];
  stats: QueueStats | null = null;
  total = 0;
  hasMore = false;
  prefix = '';
  tablesExist = true;
  tables: { failed: string; jobs: string } = { failed: '', jobs: '' };
  offset = 0;
  limit = 20;
  queueFilter = '';
  search = '';
  error: string | null = null;

  constructor() {
    this.load();
  }

  get apiBase(): string {
    return app.forum.attribute('apiUrl') + '/queue-manager';
  }

  resetError() {
    this.error = null;
  }

  async load(force = true) {
    if (this.loading && !force) return;
    this.loading = true;
    m.redraw();
    try {
      const params = new URLSearchParams();
      params.set('limit', String(this.limit));
      params.set('offset', String(this.offset));
      if (this.queueFilter) params.set('filter[queue]', this.queueFilter);
      if (this.search) params.set('filter[qtext]', this.search);

      const res: ListResponse = await app.request({
        method: 'GET',
        url: this.apiBase + '/jobs?' + params.toString(),
      });

      this.rows = res.data;
      this.total = res.total;
      this.hasMore = res.hasMore;
      this.stats = res.stats;
      this.prefix = res.prefix;
      this.tablesExist = res.tablesExist;
      this.tables = res.tables;
    } catch (e) {
      this.error = e && (e as any).statusReason ? (e as any).statusReason : String(e);
    } finally {
      this.loading = false;
      m.redraw();
    }
  }

  refresh() {
    return this.load(true);
  }

  nextPage() {
    this.offset += this.limit;
    return this.load(true);
  }

  prevPage() {
    this.offset = Math.max(0, this.offset - this.limit);
    return this.load(true);
  }

  applyFilters() {
    this.offset = 0;
    return this.load(true);
  }

  async requeue(id: number): Promise<boolean> {
    try {
      const res = await app.request({
        method: 'POST',
        url: this.apiBase + '/jobs/' + id + '/requeue',
      });
      app.alerts.show({ type: 'success' }, app.translator.trans('toreador-flarum-job-queue.admin.table.requeued', { id }));
      await this.load(true);
      return !!(res as any) && (res as any).ok;
    } catch (e) {
      this.surfaceError(e);
      return false;
    }
  }

  async requeueAll(queue: string | null): Promise<boolean> {
    try {
      const res = await app.request({
        method: 'POST',
        url: this.apiBase + '/jobs/requeue',
        body: queue ? { queue } : {},
      });
      const count = (res as any).requeued || 0;
      app.alerts.show({ type: 'success' }, app.translator.trans('toreador-flarum-job-queue.admin.table.requeued_all', { count }));
      await this.load(true);
      return true;
    } catch (e) {
      this.surfaceError(e);
      return false;
    }
  }

  async remove(id: number): Promise<boolean> {
    try {
      await app.request({ method: 'DELETE', url: this.apiBase + '/jobs/' + id });
      app.alerts.show({ type: 'success' }, app.translator.trans('toreador-flarum-job-queue.admin.table.deleted', { id }));
      await this.load(true);
      return true;
    } catch (e) {
      this.surfaceError(e);
      return false;
    }
  }

  async clear(queue: string | null): Promise<boolean> {
    try {
      const res = await app.request({
        method: 'POST',
        url: this.apiBase + '/jobs/clear',
        body: queue ? { queue } : {},
      });
      const count = (res as any).deleted || 0;
      app.alerts.show({ type: 'success' }, app.translator.trans('toreador-flarum-job-queue.admin.table.cleared', { count }));
      await this.load(true);
      return true;
    } catch (e) {
      this.surfaceError(e);
      return false;
    }
  }

  surfaceError(e: any) {
    const reason = e && e.statusReason ? e.statusReason : String(e && e.message ? e.message : e);
    app.alerts.show({ type: 'error' }, reason);
  }
}