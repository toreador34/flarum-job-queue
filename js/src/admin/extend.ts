import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import LinkButton from 'flarum/common/components/LinkButton';
import QueueManagerPage from './components/QueueManagerPage';

export default [
  new Extend.Routes().add('queue-manager', '/queue-manager', QueueManagerPage),

  new Extend.Admin()
    .customSetting(() =>
      m(
        'div',
        { className: 'Form-group' },
        m(
          LinkButton,
          {
            className: 'Button Button--primary',
            icon: 'fas fa-tasks',
            href: app.route('queue-manager'),
          },
          app.translator.trans('toreador-flarum-job-queue.admin.settings.open_list_button')
        ),
        m(
          'p',
          { className: 'helpText' },
          app.translator.trans('toreador-flarum-job-queue.admin.settings.open_list_help')
        )
      )
    )
    .setting(() => ({
      setting: 'toreador-flarum-job-queue.table_prefix',
      type: 'text',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.table_prefix_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.table_prefix_help'),
      placeholder: 'toreador34_',
    }))
    .setting(() => ({
      setting: 'toreador-flarum-job-queue.auto_requeue',
      type: 'bool',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_help'),
    }))
    .setting(() => ({
      setting: 'toreador-flarum-job-queue.auto_requeue_after',
      type: 'number',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_after_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_after_help'),
      min: 0,
    }))
    .setting(() => ({
      setting: 'toreador-flarum-job-queue.auto_requeue_max_attempts',
      type: 'number',
      label: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_max_attempts_label'),
      help: app.translator.trans('toreador-flarum-job-queue.admin.settings.auto_requeue_max_attempts_help'),
      min: 0,
    })),
];
