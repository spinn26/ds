import { defineStore } from 'pinia';

/**
 * Лёгкий разделяемый стор статусов сдачи тестов курсов.
 *
 * Цель — чтобы успешная сдача теста на /education/courses/:id/test мгновенно
 * отражалась в списках и карточках (Обучение, урок-тест) без перезахода/
 * рефетча. Страницы при загрузке /education/tree засевают сюда уже сданные
 * курсы (seedPassed), EducationTest после успеха вызывает markPassed.
 *
 * Источник истины остаётся серверным (testPassed из education_course_completions);
 * стор лишь даёт оптимистичный, реактивный слой поверх него. Сдача монотонна
 * (курс не «разсдаётся»), поэтому стор только добавляет id.
 */
export const useEducationStore = defineStore('education', {
  state: () => ({
    passedCourseIds: [],
  }),
  getters: {
    isPassed: (state) => (courseId) => state.passedCourseIds.includes(Number(courseId)),
  },
  actions: {
    markPassed(courseId) {
      const id = Number(courseId);
      if (!Number.isNaN(id) && !this.passedCourseIds.includes(id)) {
        this.passedCourseIds.push(id);
      }
    },
    /** Засеять статусы из дерева/списка курсов (узлы с testPassed === true). */
    seedFromCourses(nodes) {
      const walk = (list) => {
        for (const c of list || []) {
          if (c?.testPassed) this.markPassed(c.id);
          if (c?.children?.length) walk(c.children);
        }
      };
      walk(nodes);
    },
  },
});
