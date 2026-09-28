import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
const pages = import.meta.glob<DefineComponent>('./modules/**/pages/*.vue');
void createInertiaApp({
  title: (title) => (title ? `${title} · RibkaYoga` : 'RibkaYoga'),
  resolve: async (name) => {
    const [module, page] = name.split('/');
    const load = pages[`./modules/${module}/pages/${page}.vue`];
    if (!load) throw new Error(`Не найден экран ${name}`);
    return load();
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el);
  },
  progress: { color: '#397a28' },
});
