import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Modal from 'flarum/common/components/Modal';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

export default class FailedJobDetailModal extends Modal {
  id!: number;
  detail: any = null;
  loading = true;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.id = vnode.attrs.id;
    this.fetch();
  }

  className() {
    return 'QueueManagerModal Modal--large';
  }

  title() {
    return app.translator.trans('toreador-flarum-job-queue.admin.modal.title', { id: this.id });
  }

  content() {
    if (this.loading || !this.detail) {
      return m('.Modal-body', m(LoadingIndicator));
    }

    const d = this.detail;

    return (
      <div className="Modal-body">
        <div className="QueueManagerModal-description">
          <h4>{app.translator.trans('toreador-flarum-job-queue.admin.modal.description')}</h4>
          <p>{d.description}</p>
          {d.command_class ? <p>
            <code>{d.command_class}</code>
          </p> : null}
          {d.connection ? <p>
            <small>{d.connection}</small>
          </p> : null}
        </div>

        <h4>{app.translator.trans('toreador-flarum-job-queue.admin.modal.payload')}</h4>
        <pre>{d.payload}</pre>

        {d.exception ? [
          <h4>{app.translator.trans('toreador-flarum-job-queue.admin.modal.exception')}</h4>,
          <pre>{d.exception}</pre>,
        ] : null}
      </div>
    );
  }

  async fetch() {
    try {
      const res = await app.request({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/queue-manager/jobs/' + this.id,
      });
      this.detail = res.data;
    } catch (e) {
      app.alerts.show({
        type: 'error',
        children: e && e.statusReason ? e.statusReason : String(e),
      });
    } finally {
      this.loading = false;
      m.redraw();
    }
  }
}