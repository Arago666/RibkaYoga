<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
  ArrowDown,
  ArrowRight,
  ArrowUpRight,
  Check,
  ChevronDown,
  FileDown,
  Layers3,
  Leaf,
  Menu,
  ScanText,
  Timer,
  X,
} from '@lucide/vue';
import PracticeDemo from '../components/PracticeDemo.vue';
import '@/../css/landing.css';

withDefaults(defineProps<{ socialImage?: string }>(), {
  socialImage: '/images/brand/glass-garden-hero.webp',
});

const page = usePage();
const signedIn = computed(() => Boolean((page.props.auth as { user?: unknown } | undefined)?.user));
const destination = computed(() => (signedIn.value ? '/practices' : '/register'));
const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement>();
const header = ref<HTMLElement>();
const root = ref<HTMLElement>();
const motionReady = ref(false);
let observer: IntersectionObserver | undefined;
function closeMenu() {
  menuOpen.value = false;
}
function dismissMenu(event: PointerEvent) {
  if (event.target instanceof Node && !header.value?.contains(event.target)) closeMenu();
}
function escapeMenu(event: KeyboardEvent) {
  if (event.key === 'Escape' && menuOpen.value) {
    closeMenu();
    void nextTick(() => menuButton.value?.focus());
  }
}
const workflow = [
  {
    number: '01',
    title: 'Соберите',
    text: 'Рисунки, строки и блоки. Последовательность складывается под вашу задачу.',
  },
  {
    number: '02',
    title: 'Проведите',
    text: 'Задайте время асан, отдых и ритм. Или возьмите с собой готовый лист.',
  },
  {
    number: '03',
    title: 'Осмыслите',
    text: 'Сохраните наблюдения, выделите связи. Следующая практика начинается с опыта.',
  },
];
const faq = [
  {
    question: 'Можно использовать свои рисунки?',
    answer:
      'Да. Загружайте готовые изображения в библиотеку или рисуйте в скетчбуке. Свои асаны можно собирать в подборки и использовать в практиках.',
  },
  {
    question: 'Система составляет практику за меня?',
    answer:
      'Последовательность создаёте вы. RibkaYoga помогает собрать и сохранить вашу задумку: от отдельных асан до строк, блоков и программ.',
  },
  {
    question: 'Можно распечатать последовательность?',
    answer:
      'У практики есть предварительный просмотр и PDF. Можно выбрать ориентацию листа и показать или скрыть названия. Пометки разбора тоже доступны в отдельном PDF.',
  },
  {
    question: 'Подойдёт для телефона или планшета?',
    answer:
      'Да. Интерфейс адаптируется к экрану. На компьютере удобно собирать длинные последовательности, а телефон и планшет можно использовать для занятия и работы с заметками.',
  },
  {
    question: 'Как поделиться практикой?',
    answer:
      'В кабинете можно предоставить доступ к практике другому пользователю. Получатель видит доступную ему практику и может создавать свой разбор с личными пометками.',
  },
];
onMounted(() => {
  document.addEventListener('pointerdown', dismissMenu);
  document.addEventListener('keydown', escapeMenu);
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
    observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries)
          if (entry.isIntersecting) {
            entry.target.classList.add('is-revealed');
            observer?.unobserve(entry.target);
          }
      },
      { threshold: 0.08 },
    );
    root.value?.querySelectorAll('[data-reveal]').forEach((element) => observer?.observe(element));
    motionReady.value = true;
  }
});
onBeforeUnmount(() => {
  observer?.disconnect();
  document.removeEventListener('pointerdown', dismissMenu);
  document.removeEventListener('keydown', escapeMenu);
});
</script>

<template>
  <Head title="Йога, собранная по-вашему">
    <meta
      head-key="description"
      name="description"
      content="От замысла до занятия: собирайте последовательности асан, ведите практику с таймером, делайте личные разборы и печатайте готовые листы в RibkaYoga."
    />
    <meta head-key="og-title" property="og:title" content="RibkaYoga — йога, собранная по-вашему" />
    <meta
      head-key="og-description"
      property="og:description"
      content="Ваше пространство для создания, проведения и осмысления практики."
    />
    <meta head-key="og-type" property="og:type" content="website" />
    <meta head-key="og-image" property="og:image" :content="socialImage" />
  </Head>
  <div ref="root" :class="['landing', { 'motion-ready': motionReady }]">
    <a class="landing-skip" href="#main-content">Перейти к содержимому</a>
    <header ref="header" class="landing-nav">
      <Link href="/" class="brand landing-brand" aria-label="RibkaYoga — главная"
        ><img src="/favicon.svg" alt="" width="36" height="36" /><span
          >ribka<span class="brand-light">yoga</span></span
        ></Link
      >
      <nav
        :class="['landing-links', { 'is-open': menuOpen }]"
        id="landing-menu"
        aria-label="Навигация по главной"
      >
        <a href="#possibilities" @click="closeMenu">Возможности</a
        ><a href="#how-it-works" @click="closeMenu">Как это работает</a
        ><a href="#for-you" @click="closeMenu">Для кого</a><a href="#questions" @click="closeMenu">Вопросы</a>
      </nav>
      <div class="landing-nav-actions">
        <Link :href="signedIn ? '/practices' : '/login'" class="landing-login"
          >{{ signedIn ? 'Мои практики' : 'Войти' }}<ArrowUpRight :size="15" /></Link
        ><Link :href="destination" class="landing-button nav-start"
          >{{ signedIn ? 'В кабинет' : 'Начать'
          }}<span class="button-orb"><ArrowUpRight :size="17" /></span></Link
        ><button
          ref="menuButton"
          class="landing-menu-button"
          type="button"
          :aria-expanded="menuOpen"
          aria-controls="landing-menu"
          :aria-label="menuOpen ? 'Закрыть меню' : 'Открыть меню'"
          @click="menuOpen = !menuOpen"
        >
          <component :is="menuOpen ? X : Menu" :size="23" />
        </button>
      </div>
    </header>

    <main id="main-content">
      <section class="landing-hero landing-container">
        <div class="hero-copy" data-reveal>
          <span class="landing-kicker"><span class="kicker-dot"></span>Пространство вашей практики</span>
          <h1>
            Йога,<br />собранная<br /><span class="hero-last-line"
              >по-вашему<span class="hero-dot">.</span></span
            >
          </h1>
          <p class="hero-description">
            Место, где ваша идея становится практикой.<br class="desktop-break" />
            Собирайте последовательности, ведите занятия<br class="desktop-break" />
            и сохраняйте то, что важно именно вам.
          </p>
          <div class="hero-actions">
            <Link :href="destination" class="landing-button"
              >{{ signedIn ? 'Открыть мои практики' : 'Создать свою практику'
              }}<span class="button-orb"><ArrowUpRight :size="19" /></span></Link
            ><a href="#possibilities" class="landing-watch"
              >Посмотреть, как это работает<ArrowDown :size="16"
            /></a>
          </div>
          <div class="hero-bottom">
            <span>Замысел.</span><span>Движение.</span><span>Внимание.</span
            ><span class="hero-bottom-rule"></span>
          </div>
        </div>
        <div class="hero-art" data-reveal>
          <div class="hero-art-frame">
            <img
              src="/images/brand/glass-garden-hero.webp"
              alt="Прозрачная стеклянная скульптура над зелёным островом мха"
              class="hero-art-image"
              width="1536"
              height="1024"
              fetchpriority="high"
            /><svg class="hero-orbit" viewBox="0 0 560 620" fill="none" aria-hidden="true">
              <ellipse cx="275" cy="318" rx="235" ry="279" transform="rotate(27 275 318)" />
              <ellipse cx="275" cy="318" rx="228" ry="270" transform="rotate(27 275 318)" />
              <path d="M 482 150 h 25 m -12.5 -12.5 v 25" />
            </svg>
          </div>
          <div class="hero-art-caption">
            <span class="caption-symbol"><Leaf :size="19" :stroke-width="1.3" /></span>
            <div><span>Свобода в структуре</span><small>Меньше рутины. Больше внимания.</small></div>
          </div>
          <span class="hero-art-label">В своём ритме / RibkaYoga</span>
        </div>
      </section>

      <div class="landing-container">
        <div class="landing-flow" data-reveal>
          <span class="flow-intro">От замысла<br />к живой практике</span><span class="flow-line"></span
          ><span><span class="flow-number">01</span>Создавайте</span><ArrowRight :size="18" /><span
            ><span class="flow-number">02</span>Практикуйте</span
          ><ArrowRight :size="18" /><span><span class="flow-number">03</span>Развивайтесь</span>
        </div>
      </div>

      <section id="possibilities" class="landing-product landing-container">
        <div class="landing-section-heading" data-reveal>
          <span class="landing-kicker">01 / Ваша система</span>
          <h2>Всё складывается.<br /><span>В вашу практику.</span></h2>
          <p>
            Отдельные инструменты — в одном спокойном пространстве.<br class="desktop-break" />
            От первой асаны до последней заметки после занятия.
          </p>
        </div>
        <div class="product-stage" data-reveal>
          <svg class="stage-contours" viewBox="0 0 800 700" fill="none" aria-hidden="true">
            <ellipse
              v-for="n in 9"
              :key="n"
              cx="550"
              cy="380"
              :rx="130 + n * 28"
              :ry="110 + n * 34"
              transform="rotate(-30 550 380)"
            />
          </svg>
          <div class="stage-copy">
            <span class="stage-label"><span></span>Ваша идея. Ваш почерк.</span>
            <h3>Есть структура.<br />Есть свобода<br /><em>внутри неё.</em></h3>
            <p>
              Соединяйте асаны, меняйте порядок, добавляйте свои рисунки. Затем проведите занятие или
              посмотрите на него внимательнее.
            </p>
            <a href="#interactive-demo" class="stage-demo-link"
              >Попробуйте прямо здесь<ArrowRight :size="17" /></a
            ><span class="stage-index">R / 01—03</span>
          </div>
          <div id="interactive-demo" class="stage-demo"><PracticeDemo /></div>
        </div>
        <div class="product-details" data-reveal>
          <article>
            <span class="detail-icon"><Layers3 :size="22" :stroke-width="1.5" /></span>
            <h3>Собственное видение</h3>
            <p>
              Библиотека асан, свои изображения и скетчбук. Строки и блоки, которые удобно переиспользовать.
            </p>
          </article>
          <article>
            <span class="detail-icon"><ScanText :size="22" :stroke-width="1.5" /></span>
            <h3>Больше смысла</h3>
            <p>Личные комментарии, цветные группы и связи между асанами в отдельном разборе практики.</p>
          </article>
          <article>
            <span class="detail-icon"><Timer :size="22" :stroke-width="1.5" /></span>
            <h3>Свой ритм</h3>
            <p>
              Время удержания, отдых, метроном и медитация. Настройки, которые подстраиваются под занятие.
            </p>
          </article>
        </div>
      </section>

      <section id="for-you" class="landing-audience landing-container">
        <div class="audience-visual" data-reveal>
          <img
            src="/images/brand/studio-practice.webp"
            alt="Преподавательница йоги в позе воина в светлой студии"
            width="1024"
            height="1536"
            loading="lazy"
          /><span class="audience-photo-label"
            ><span class="kicker-dot"></span>Практика начинается с внимания</span
          ><svg class="audience-cross" width="52" height="52" viewBox="0 0 52 52" aria-hidden="true">
            <path d="M26 0v52M0 26h52M8 8l36 36M8 44 44 8" fill="none" stroke="currentColor" />
          </svg>
        </div>
        <div class="audience-copy" data-reveal>
          <span class="landing-kicker">02 / Для вас</span>
          <h2>Для тех,<br />кто практикует.<br /><span>И ведёт за собой.</span></h2>
          <p class="audience-intro">
            Хороший инструмент не задаёт ваш метод.<br />Он помогает ему раскрыться.
          </p>
          <div class="audience-item">
            <span>01</span>
            <div>
              <h3>Преподавателям</h3>
              <p>Собирайте занятия, сохраняйте удачные связки и делитесь практиками с учениками.</p>
            </div>
            <ArrowUpRight :size="19" />
          </div>
          <div class="audience-item">
            <span>02</span>
            <div>
              <h3>Для личной практики</h3>
              <p>Подготовьте последовательность, включите таймер и вернитесь к своим наблюдениям после.</p>
            </div>
            <ArrowUpRight :size="19" />
          </div>
          <div class="audience-item">
            <span>03</span>
            <div>
              <h3>Студиям и наставникам</h3>
              <p>Объединяйте занятия в программы и циклы. Держите материалы в понятной структуре.</p>
            </div>
            <ArrowUpRight :size="19" />
          </div>
        </div>
      </section>

      <section id="how-it-works" class="landing-workflow landing-container">
        <div class="landing-section-heading workflow-heading" data-reveal>
          <span class="landing-kicker">03 / От идеи к занятию</span>
          <h2>Продумано заранее.<br /><span>Прожито в моменте.</span></h2>
        </div>
        <div class="workflow-grid" data-reveal>
          <article v-for="step in workflow" :key="step.number">
            <span class="workflow-number"
              >{{ step.number }}<ArrowUpRight :size="22" :stroke-width="1.2"
            /></span>
            <h3>{{ step.title }}</h3>
            <p>{{ step.text }}</p>
          </article>
        </div>
        <div class="paper-feature" data-reveal>
          <div class="paper-copy">
            <span class="detail-icon"><FileDown :size="23" :stroke-width="1.5" /></span>
            <h3>Цифровая свобода.<br />Бумажная простота.</h3>
            <p>
              План на экране или лист рядом с ковриком — выбирайте сами. Предварительный просмотр и PDF
              сохраняют последовательность понятной.
            </p>
            <span class="paper-tag"><Check :size="14" />Названия можно скрыть</span>
          </div>
          <div class="paper-sheet" aria-label="Пример печатного листа с рисунками асан">
            <div class="paper-sheet-header"><span>Мягкое начало</span><small>Пример листа</small></div>
            <div class="paper-sheet-row">
              <span>1.1</span><img src="/images/brand/poses/seated.svg" alt="" /><i>→</i
              ><img src="/images/brand/poses/standing.svg" alt="" /><i>→</i
              ><img src="/images/brand/poses/downward.svg" alt="" />
            </div>
            <div class="paper-sheet-row">
              <span>1.2</span><img src="/images/brand/poses/upward.svg" alt="" /><i>→</i
              ><img src="/images/brand/poses/seated.svg" alt="" /><i>→</i
              ><img src="/images/brand/poses/rest.svg" alt="" />
            </div>
            <div class="paper-sheet-footer"><span>RibkaYoga</span><span>01</span></div>
          </div>
        </div>
      </section>

      <section id="questions" class="landing-faq landing-container">
        <div data-reveal>
          <span class="landing-kicker">04 / Всё понятно</span>
          <h2>Ещё немного<br /><span>ясности.</span></h2>
          <div class="faq-drawing" aria-hidden="true">
            <svg viewBox="0 0 230 200" fill="none">
              <ellipse cx="111" cy="100" rx="93" ry="46" transform="rotate(-32 111 100)" />
              <ellipse cx="111" cy="100" rx="93" ry="46" transform="rotate(32 111 100)" />
              <circle cx="111" cy="100" r="8" />
            </svg>
          </div>
        </div>
        <div class="landing-faq-list" data-reveal>
          <details v-for="item in faq" :key="item.question" class="disclosure">
            <summary>
              <span class="disclosure-copy">{{ item.question }}</span
              ><ChevronDown class="disclosure-chevron" :size="19" />
            </summary>
            <div class="disclosure-body">{{ item.answer }}</div>
          </details>
        </div>
      </section>

      <section class="landing-cta landing-container" data-reveal>
        <div class="cta-inner">
          <svg class="cta-orbits" viewBox="0 0 650 600" fill="none" aria-hidden="true">
            <ellipse
              v-for="n in 7"
              :key="n"
              cx="400"
              cy="290"
              :rx="100 + n * 34"
              :ry="80 + n * 30"
              transform="rotate(-32 400 290)"
            /></svg
          ><span class="landing-kicker"><span class="kicker-dot"></span>Всё начинается с вашей идеи</span>
          <h2>Вдохните.<br />И создайте <em>своё.</em></h2>
          <p>Ваша следующая практика уже складывается.</p>
          <Link :href="destination" class="landing-button cta-button"
            >{{ signedIn ? 'Вернуться к практике' : 'Открыть своё пространство'
            }}<span class="button-orb"><ArrowUpRight :size="20" /></span></Link
          ><span class="cta-aside">Внимание к деталям.<br />Пространство для роста.</span>
        </div>
      </section>
    </main>
    <footer class="landing-footer landing-container">
      <div class="landing-footer-top">
        <Link href="/" class="brand" aria-label="RibkaYoga — главная"
          ><img src="/favicon.svg" alt="" width="32" height="32" /><span
            >ribka<span class="brand-light">yoga</span></span
          ></Link
        ><span>Место для вашей практики.</span>
        <div>
          <Link :href="signedIn ? '/practices' : '/login'"
            >{{ signedIn ? 'Мои практики' : 'Войти' }}<ArrowUpRight :size="14" /></Link
          ><a href="#main-content">Наверх<ArrowUpRight :size="14" /></a>
        </div>
      </div>
      <div class="landing-footer-bottom">
        <span>© {{ new Date().getFullYear() }} RibkaYoga</span
        ><span>Создавайте осознанно. Практикуйте в своём ритме.</span>
      </div>
    </footer>
  </div>
</template>
