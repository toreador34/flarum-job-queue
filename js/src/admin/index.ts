import app from 'flarum/admin/app';
import AdminNav from 'flarum/admin/components/AdminNav';
import LinkButton from 'flarum/common/components/LinkButton';
import { extend } from 'flarum/common/extend';
import QueueManagerPage from './components/QueueManagerPage';

app.initializers.add('toreador-job-queue', () => {
  app.routes['queue-manager'] = {
    path: '/queue-manager',
    component: QueueManagerPage,
  };

  extend(AdminNav.prototype, 'items', function (items) {
    items.add(
      'queue-manager',
      m(LinkButton, {
        href: app.route('queue-manager'),
        icon: 'fas fa-tasks',
        title: app.translator.trans('toreador-flarum-job-queue.admin.nav.title'),
      }, [
        app.translator.trans('toreador-flarum-job-queue.admin.nav.queue_manager'),
      ]),
      45
    );
  });

  app.extensionData
    .for('toreador-job-queue')
    .registerSetting({
      setting: 'toreador-flarum-job-queue.table_prefix',
      type: 'text',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.table_prefix_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.table_prefix_help'),
      placeholder: 'toreador34_',
    })
    .registerSetting({
      setting: 'toreador-flarum-job-queue.auto_requeue',
      type: 'bool',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_help'),
    })
    .registerSetting({
      setting: 'toreador-flarum-job-queue.auto_requeue_after',
      type: 'number',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_after_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_after_help'),
      min: 0,
    })
    .registerSetting({
      setting: 'toreador-flarum-job-queue.auto_requeue_max_attempts',
      type: 'number',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_max_attempts_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_max_attempts_help'),
      min: 0,
    });
});
