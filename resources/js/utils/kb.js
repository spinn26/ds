import {
    Award,
    Bell,
    BookOpen,
    Briefcase,
    Calculator,
    CalendarDays,
    ChartColumn,
    ClipboardList,
    Download,
    FileText,
    Flag,
    Folder,
    GraduationCap,
    Heart,
    Image,
    Info,
    Lightbulb,
    Link2,
    Lock,
    Mail,
    Map,
    Megaphone,
    MessageCircle,
    Package,
    Phone,
    Plus,
    Presentation,
    Scale,
    Search,
    Settings,
    ShieldCheck,
    Star,
    Tag,
    Users,
    Video,
    Wallet,
} from 'lucide-vue-next';

/**
 * Иконка раздела базы знаний хранится в БД строкой-именем MDI: её вводит
 * руками отдел обучения в конструкторе (`education_kb_sections.icon`).
 * Новый дизайн рисует lucide, поэтому подбираем компонент по ключевому
 * слову в имени — так любое ещё не встречавшееся mdi-имя даст осмысленную
 * иконку, а не дыру. Порядок правил важен: частное раньше общего.
 */
const RULES = [
    [/bell|notif/, Bell],
    [/video|movie|play|youtube|film/, Video],
    [/presentation|projector|slide/, Presentation],
    [/clipboard|checklist|list|format-align/, ClipboardList],
    [/file|document|text|note|pdf|paper|script/, FileText],
    [/book|library|shelf/, BookOpen],
    [/school|teach|academ|student/, GraduationCap],
    [/certificate|medal|trophy|award|crown/, Award],
    [/chart|graph|poll|trending|finance-line/, ChartColumn],
    [/package|cube|shape|box/, Package],
    [/bullhorn|megaphone|announce|speaker/, Megaphone],
    [/calendar|event|clock|timer/, CalendarDays],
    [/link|web|url|share/, Link2],
    [/lightbulb|idea|creation/, Lightbulb],
    [/star/, Star],
    [/information|help|question|alert|comment-question/, Info],
    [/download|export|tray-arrow/, Download],
    [/image|picture|camera|palette/, Image],
    [/briefcase|office|domain|bank|city/, Briefcase],
    [/calculator|abacus/, Calculator],
    [/scale|gavel|law|balance/, Scale],
    [/message|chat|comment|forum/, MessageCircle],
    [/email|mail|envelope/, Mail],
    [/phone|cellphone|headset/, Phone],
    [/map|earth|globe|compass/, Map],
    [/cog|settings|wrench|tool/, Settings],
    [/magnify|search/, Search],
    [/account|human|people|group|team/, Users],
    [/shield|security|verified/, ShieldCheck],
    [/cash|currency|wallet|credit-card|coin|ruble/, Wallet],
    [/tag|label|bookmark/, Tag],
    [/lock|key/, Lock],
    [/flag/, Flag],
    [/heart/, Heart],
    [/plus/, Plus],
    [/folder|archive/, Folder],
];

/** Компонент lucide по mdi-имени из БД. Без совпадения — папка. */
export function kbIcon(mdi) {
    const name = String(mdi || '').toLowerCase();
    if (!name) return Folder;
    for (const [re, icon] of RULES) {
        if (re.test(name)) return icon;
    }
    return Folder;
}

/** Русское склонение: plural(3, 'материал', 'материала', 'материалов'). */
export function plural(n, one, few, many) {
    const abs = Math.abs(Number(n) || 0);
    const tail100 = abs % 100;
    const tail10 = abs % 10;
    if (tail100 > 10 && tail100 < 20) return many;
    if (tail10 > 1 && tail10 < 5) return few;
    if (tail10 === 1) return one;
    return many;
}

/** «12 материалов» — число вместе со склонённым словом. */
export function countLabel(n, one, few, many) {
    return `${n} ${plural(n, one, few, many)}`;
}
