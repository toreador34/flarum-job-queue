import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import icon from 'flarum/common/helpers/icon';
import humanTime from 'flarum/common/utils/humanTime';
import FailedJobDetailModal from './FailedJobDetailModal';
import QueueManagerState from '../states/QueueManagerState';

export default class QueueManagerPage extends Component {
  state = new QueueManagerState();
  autoRefresh = false;
  autoRefreshInterval: any = null;
  searchValue = '';
  searchTimer: any = null;
  searchDirty = false;

  onremove() {
    this.stopAutoRefresh();
  }

  startAutoRefresh() {
    this.stopAutoRefresh();
    this.autoRefreshInterval = setInterval(() => {
      this.state.refresh();
    }, 5000);
  }

  stopAutoRefresh() {
    if (this.autoRefreshInterval) {
      clearInterval(this.autoRefreshInterval);
      this.autoRefreshInterval = null;
    }
  }

  toggleAutoRefresh(value: boolean) {
    this.autoRefresh = value;
    if (value) {
      this.startAutoRefresh();
    } else {
      this.stopAutoRefresh();
    }
  }

  onSearchInput(event: any) {
    this.searchValue = event.target.value;
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => {
      this.state.search = this.searchValue;
      this.searchTimer = null;
      this.state.applyFilters();
    }, 350);
  }

  confirm(message: string): boolean {
    return window.confirm(message);
  }

  requeueClick(row: any) {
    const msg = app.translator.trans('toreador-flarum-job-queue.admin.table.requeue_confirm', { id: row.id });
    if (!this.confirm(msg)) return;
    this.state.requeue(row.id);
  }

  requeueAllClick() {
    const msg = app.translator.trans('toreador-flarum-job-queue.admin.filters.requeue_all_confirm');
    if (!this.confirm(msg)) return;
    this.state.requeueAll(this.state.queueFilter || null);
  }

  deleteClick(row: any) {
    const msg = app.translator.trans('toreador-flarum-job-queue.admin.table.delete_confirm', { id: row.id });
    if (!this.confirm(msg)) return;
    this.state.remove(row.id);
  }

  clearAllClick() {
    const msg = app.translator.trans('toreador-flarum-job-queue.admin.filters.clear_all_confirm');
    if (!this.confirm(msg)) return;
    this.state.clear(this.state.queueFilter || null);
  }

  view() {
    return (
      <div className="QueueManagerPage">
        <div className="container">
          <h2>{app.translator.trans('toreador-flarum-job-queue.admin.page.title')}</h2>
          <p>{app.translator.trans('toreador-flarum-job-queue.admin.page.subtitle')}</p>

          {this.banner()}
          {this.stats()}
          {this.toolbar()}
          {this.table()}
          {this.pagination()}
        </div>
      </div>
    );
  }

  banner() {
    const banners = [];

    if (!this.state.tablesExist) {
      banners.push(
        m('.alert.alert-danger', app.translator.trans('toreador-flarum-job-queue.admin.page.tables_missing'))
      );
    }

    const autoRequeue = !!app.data.settings['toreador-flarum-job-queue.auto_requeue'];
    if (autoRequeue) {
      banners.push(
        m('.alert.alert-success', app.translator.trans('toreador-flarum-job-queue.admin.page.auto_requeue_banner'))
      );
    }

    if (this.state.error) {
      banners.push(m('.alert.alert-danger', this.state.error));
    }

    if (!banners.length) return null;

    return m('.QueueManagerPage-banner', banners);
  }

  stats() {
    const s = this.state;
    const stats = s.stats || { pending: 0, failed: 0, queues: [] };

    return (
      <div className="QueueManagerPage-stats">
        <div className="QueueManagerPage-stat" title={app.translator.trans('toreador-flarum-job-queue.admin.page.stats.pending_help')}>
          <small>{app.translator.trans('toreador-flarum-job-queue.admin.page.stats.pending')}</small>
          <p>{stats.pending}</p>
        </div>
        <div className="QueueManagerPage-stat" title={app.translator.trans('toreador-flarum-job-queue.admin.page.stats.failed_help')}>
          <small>{app.translator.trans('toreador-flarum-job-queue.admin.page.stats.failed')}</small>
          <p>{stats.failed}</p>
        </div>
        <div className="QueueManagerPage-stat" title={app.translator.trans('toreador-flarum-job-queue.admin.page.stats.prefix_help')}>
          <small>{app.translator.trans('toreador-flarum-job-queue.admin.page.stats.prefix')}</small>
          <p>
            <code>
              {s.stats ? s.prefix || app.translator.trans('toreador-flarum-job-queue.admin.page.stats.prefix_none') : '…'}
            </code>
          </p>
        </div>
        <div className="QueueManagerPage-stat">
          <small>{app.translator.trans('toreador-flarum-job-queue.admin.page.stats.failed_table')}</small>
          <p>
            <code>{s.tables.failed || '…'}</code>
          </p>
        </div>
        <div className="QueueManagerPage-stat">
          <small>{app.translator.trans('toreador-flarum-job-queue.admin.page.stats.jobs_table')}</small>
          <p>
            <code>{s.tables.jobs || '…'}</code>
          </p>
        </div>
      </div>
    );
  }

  toolbar() {
    const s = this.state;
    const queues = [
      { queue: '', label: app.translator.trans('toreador-flarum-job-queue.admin.filters.all_queues') },
    ];
    (s.stats?.queues || []).forEach((q) => {
      queues.push({ queue: q.queue, label: `${q.queue} (${q.count})` });
    });

    const selectOptions: any = {};
    queues.forEach((q) => {
      selectOptions[q.queue] = q.label;
    });

    return (
      <div className="QueueManagerPage-toolbar">
        <Button
          className="Button Button--icon"
          icon="fas fa-sync-alt"
          onclick={() => s.refresh()}
          disabled={s.loading}
          title={app.translator.trans('toreador-flarum-job-queue.admin.page.refresh')}
        />
        <Switch
          state={this.autoRefresh}
          onchange={(v: boolean) => this.toggleAutoRefresh(v)}
          className="QueueManagerPage-autoRefresh"
        >
          {app.translator.trans('toreador-flarum-job-queue.admin.page.auto_refresh')}
        </Switch>

        <div className="QueueManagerPage-toolbar-spacer" />

        <input
          className="FormControl"
          type="search"
          placeholder={app.translator.trans('toreador-flarum-job-queue.admin.filters.search_placeholder')}
          value={this.searchValue}
          oninput={(e: any) => this.onSearchInput(e)}
        />
        <Select
          options={selectOptions}
          value={s.queueFilter}
          onchange={(v: string) => {
            s.queueFilter = v;
            s.applyFilters();
          }}
        />
        <Button
          className="Button Button--primary"
          icon="fas fa-arrow-up"
          onclick={() => this.requeueAllClick()}
          disabled={s.loading || !s.total}
          title={app.translator.trans('toreador-flarum-job-queue.admin.filters.requeue_all_help')}
        >
          {app.translator.trans('toreador-flarum-job-queue.admin.filters.requeue_all')}
        </Button>
        <Button
          className="Button Button--danger"
          icon="fas fa-trash"
          onclick={() => this.clearAllClick()}
          disabled={s.loading || !s.total}
          title={app.translator.trans('toreador-flarum-job-queue.admin.filters.clear_all_help')}
        >
          {app.translator.trans('toreador-flarum-job-queue.admin.filters.clear_all')}
        </Button>
      </div>
    );
  }

  table() {
    const s = this.state;

    if (s.loading && !s.rows.length) {
      return (
        <div className="QueueManagerPage-table-wrap">
          {m(LoadingIndicator)}
        </div>
      );
    }

    if (!s.rows.length) {
      return (
        <div className="QueueManagerPage-table-wrap Alert">
          <div className="container">
            <p>{app.translator.trans('toreador-flarum-job-queue.admin.table.empty')}</p>
          </div>
        </div>
      );
    }

    return (
      <div className="QueueManagerPage-table-wrap">
        <table className="QueueManagerPage-table">
          <thead>
            <tr>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.id')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.queue')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.job')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.details')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.exception')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.failed_at')}</th>
              <th>{app.translator.trans('toreador-flarum-job-queue.admin.table.actions')}</th>
            </tr>
          </thead>
          <tbody>{s.rows.map((row) => this.row(row))}</tbody>
        </table>
      </div>
    );
  }

  row(row: any) {
    const meta = [];
    if (row.max_tries !== null && row.max_tries !== undefined) {
      meta.push(`${app.translator.trans('toreador-flarum-job-queue.admin.table.attempts')}: ${row.max_tries}`);
    }
    if (row.timeout !== null && row.timeout !== undefined) {
      meta.push(`${app.translator.trans('toreador-flarum-job-queue.admin.table.timeout')}: ${row.timeout}`);
    }
    if (row.backoff !== null && row.backoff !== undefined) {
      meta.push(`${app.translator.trans('toreador-flarum-job-queue.admin.table.backoff')}: ${row.backoff}`);
    }

    return (
      <tr>
        <td>#{row.id}</td>
        <td>
          <span className="Badge QueueManagerPage-queue">{row.queue}</span>
        </td>
        <td className="QueueManagerPage-job">
          <p className="QueueManagerPage-job-description">{row.description}</p>
          {row.command_class ? (
            <div className="QueueManagerPage-job-meta">
              <code>{row.command_class}</code>
            </div>
          ) : null}
          {meta.length ? <div className="QueueManagerPage-job-meta">{meta.join(' · ')}</div> : null}
        </td>
        <td className="QueueManagerPage-details">
          {row.details && row.details.length
            ? row.details.slice(0, 4).map((detail, i) => (
                <div key={i}>
                  <strong>{detail.label}:</strong> {detail.value}
                </div>
              ))
            : '—'}
        </td>
        <td className="QueueManagerPage-exception">
          {row.exception_head || '—'}
        </td>
        <td>{row.failed_at ? humanTime(row.failed_at) : '—'}</td>
        <td>
          <div className="QueueManagerPage-actions">
            <Button
              className="Button Button--icon"
              icon="fas fa-arrow-up"
              onclick={() => this.requeueClick(row)}
              disabled={this.state.loading}
              title={app.translator.trans('toreador-flarum-job-queue.admin.table.requeue_title')}
            />
            <Button
              className="Button Button--icon"
              icon="fas fa-search"
              onclick={() => app.modal.show(FailedJobDetailModal, { id: row.id })}
              title={app.translator.trans('toreador-flarum-job-queue.admin.table.details_title')}
            />
            <Button
              className="Button Button--icon Button--danger"
              icon="fas fa-times"
              onclick={() => this.deleteClick(row)}
              disabled={this.state.loading}
              title={app.translator.trans('toreador-flarum-job-queue.admin.table.delete_title')}
            />
          </div>
        </td>
      </tr>
    );
  }

  pagination() {
    const s = this.state;
    if (!s.total && s.loading) return null;

    const from = s.total ? s.offset + 1 : 0;
    const to = Math.min(s.offset + s.limit, s.total);

    return (
      <div className="QueueManagerPage-pagination">
        <span>
          {app.translator.trans('toreador-flarum-job-queue.admin.pagination.x_of_y', { from, to, total: s.total })}
        </span>
        <Button
          className="Button Button--icon"
          icon="fas fa-chevron-left"
          onclick={() => s.prevPage()}
          disabled={s.loading || s.offset === 0}
        />
        <Button
          className="Button Button--icon"
          icon="fas fa-chevron-right"
          onclick={() => s.nextPage()}
          disabled={s.loading || !s.hasMore}
        />
      </div>
    );
  }
}