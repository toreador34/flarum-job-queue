import app from 'flarum/admin/app';
import AdminNav from 'flarum/admin/components/AdminNav';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import { extend } from 'flarum/common/extend';

export { default as extend } from './extend';

app.initializers.add('toreador-job-queue', () => {
  extend(AdminNav.prototype, 'items', function (items) {
    items.add(
      'queue-manager',
      m(
        LinkButton,
        {
          href: app.route('queue-manager'),
          icon: 'fas fa-tasks',
          title: extractText(app.translator.trans('toreador-flarum-job-queue.admin.nav.title')),
        },
        app.translator.trans('toreador-flarum-job-queue.admin.nav.queue_manager')
      ),
      45
    );
  });
});
