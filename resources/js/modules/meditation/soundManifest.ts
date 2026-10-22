/** Original procedural instruments; no third-party recordings or sound libraries. */
export const soundManifest = {
  format: 'ribka-procedural-audio-v1',
  author: 'RibkaYoga',
  provenance:
    'Авторский процедурный синтез средствами Web Audio API; записей конкурентов и внешних аудиофайлов нет.',
  rights: 'Код и параметры звуков входят в собственный код проекта RibkaYoga.',
  signals: {
    gong: {
      name: 'Гонг',
      method: 'Аддитивный синтез негармонических частичных тонов с затуханием',
      fundamental: 110,
      ratios: [1, 1.48, 2.08, 2.97, 4.12],
      duration: 6,
    },
    bowl: {
      name: 'Чаша',
      method: 'Близкие пары синусоидальных обертонов с биениями и затуханием',
      fundamental: 220,
      ratios: [1, 1.006, 2.73, 2.742, 5.4],
      duration: 5,
    },
    bell: {
      name: 'Мягкий колокольчик',
      method: 'Аддитивный синтез короткого колокольного тембра',
      fundamental: 660,
      ratios: [1, 2, 2.76, 4.07],
      duration: 3,
    },
  },
  ambience: {
    forest: { name: 'Лес', method: 'Синтез ветра и птичьих свистов; не полевая запись' },
    rain: { name: 'Дождь', method: 'Фильтрованный шум и синтез капель; не полевая запись' },
    waves: { name: 'Волны', method: 'Периодическая огибающая фильтрованного шума; не полевая запись' },
    white: { name: 'Белый шум', method: 'Случайный широкополосный сигнал' },
  },
} as const;
