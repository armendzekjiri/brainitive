// Whiteboard templates as Excalidraw element skeletons (see convertToExcalidrawElements).
// Coordinates start at 0,0; the editor shifts them next to the existing content.

const STICKY = { width: 200, height: 130 };
export const STICKY_COLORS = {
  yellow: '#ffec99',
  green: '#b2f2bb',
  pink: '#ffc9c9',
  blue: '#a5d8ff',
  violet: '#eebefa',
};

let counter = 0;
const uid = (prefix) => `${prefix}-${Date.now().toString(36)}-${(counter++).toString(36)}`;

/** A sticky note: filled, borderless rounded rectangle with a label. */
export function sticky(x, y, text, color = STICKY_COLORS.yellow, size = STICKY) {
  return {
    type: 'rectangle',
    id: uid('sticky'),
    x,
    y,
    width: size.width,
    height: size.height,
    backgroundColor: color,
    fillStyle: 'solid',
    strokeColor: 'transparent',
    roundness: { type: 3 },
    label: { text, fontSize: 18, strokeColor: '#1e1e1e' },
    customData: { brainitive: 'sticky' },
  };
}

function frame(name, x, y, width, height, children) {
  return [
    ...children,
    { type: 'frame', id: uid('frame'), x, y, width, height, name, children: children.map((c) => c.id) },
  ];
}

function title(x, y, text, fontSize = 36) {
  return { type: 'text', id: uid('title'), x, y, text, fontSize, strokeColor: '#1e1e1e' };
}

/** Frames side by side, each with a few placeholder stickies. */
function columnsTemplate(heading, columns) {
  const width = 460;
  const gap = 60;
  const elements = [title(0, -90, heading)];
  columns.forEach(({ name, color, notes }, index) => {
    const x = index * (width + gap);
    const children = notes.map((note, i) => sticky(x + 20 + (i % 2) * 220, 30 + Math.floor(i / 2) * 150, note, color));
    elements.push(...frame(name, x, 0, width, 640, children));
  });
  return elements;
}

export const TEMPLATES = [
  {
    key: 'retrospective',
    name: 'Retrospective',
    description: 'Went well · To improve · Action items',
    build: () => columnsTemplate('Sprint retrospective', [
      { name: 'Went well', color: STICKY_COLORS.green, notes: ['Add a note…', 'Add a note…'] },
      { name: 'To improve', color: STICKY_COLORS.pink, notes: ['Add a note…', 'Add a note…'] },
      { name: 'Action items', color: STICKY_COLORS.blue, notes: ['Owner: …', 'Owner: …'] },
    ]),
  },
  {
    key: 'roadmap',
    name: 'Roadmap',
    description: 'Now · Next · Later',
    build: () => columnsTemplate('Roadmap', [
      { name: 'Now', color: STICKY_COLORS.green, notes: ['Initiative', 'Initiative'] },
      { name: 'Next', color: STICKY_COLORS.yellow, notes: ['Initiative', 'Initiative'] },
      { name: 'Later', color: STICKY_COLORS.violet, notes: ['Idea', 'Idea'] },
    ]),
  },
  {
    key: 'brainstorm',
    name: 'Brainstorm',
    description: 'A topic in the middle, ideas around it',
    build: () => {
      const center = { type: 'ellipse', id: uid('topic'), x: 340, y: 230, width: 320, height: 160, backgroundColor: STICKY_COLORS.blue, fillStyle: 'solid', strokeColor: '#1971c2', label: { text: 'Topic / question', fontSize: 24 } };
      const spots = [[0, 0], [400, -40], [800, 0], [0, 460], [400, 500], [800, 460]];
      return [title(0, -110, 'Brainstorm'), center, ...spots.map(([x, y]) => sticky(x, y, 'Idea…'))];
    },
  },
  {
    key: 'story-map',
    name: 'User story map',
    description: 'Activities on top, stories by release below',
    build: () => {
      const activities = ['Sign up', 'Browse', 'Buy', 'Get support'];
      const width = 240;
      const elements = [title(0, -110, 'User story map')];
      activities.forEach((activity, i) => {
        const x = i * (width + 20);
        elements.push({ type: 'rectangle', id: uid('activity'), x, y: 0, width, height: 90, backgroundColor: STICKY_COLORS.blue, fillStyle: 'solid', strokeColor: '#1971c2', roundness: { type: 3 }, label: { text: activity, fontSize: 20 } });
        elements.push(sticky(x + 20, 150, 'Story…', STICKY_COLORS.yellow));
        elements.push(sticky(x + 20, 420, 'Story…', STICKY_COLORS.yellow));
      });
      const right = activities.length * (width + 20);
      [['Release 1', 120], ['Release 2', 390]].forEach(([label, y]) => {
        elements.push({ type: 'line', id: uid('release'), x: -20, y, width: right, height: 0, points: [[0, 0], [right, 0]], strokeColor: '#868e96', strokeStyle: 'dashed' });
        elements.push({ type: 'text', id: uid('release-label'), x: -20 + right + 20, y: y - 14, text: label, fontSize: 20, strokeColor: '#868e96' });
      });
      return elements;
    },
  },
  {
    key: 'flowchart',
    name: 'Flowchart',
    description: 'Start → step → decision → outcomes',
    build: () => {
      const start = { type: 'ellipse', id: uid('start'), x: 0, y: 40, width: 180, height: 90, strokeColor: '#2f9e44', backgroundColor: STICKY_COLORS.green, fillStyle: 'solid', label: { text: 'Start' } };
      const step = { type: 'rectangle', id: uid('step'), x: 300, y: 40, width: 220, height: 90, strokeColor: '#1971c2', backgroundColor: STICKY_COLORS.blue, fillStyle: 'solid', roundness: { type: 3 }, label: { text: 'Do something' } };
      const decision = { type: 'diamond', id: uid('decision'), x: 640, y: 0, width: 220, height: 170, strokeColor: '#e8590c', backgroundColor: STICKY_COLORS.yellow, fillStyle: 'solid', label: { text: 'OK?' } };
      const yes = { type: 'rectangle', id: uid('yes'), x: 980, y: -80, width: 200, height: 80, strokeColor: '#2f9e44', roundness: { type: 3 }, label: { text: 'Yes → done' } };
      const no = { type: 'rectangle', id: uid('no'), x: 980, y: 170, width: 200, height: 80, strokeColor: '#e03131', roundness: { type: 3 }, label: { text: 'No → fix' } };
      const arrow = (from, to, x, y, width, height, text) => ({ type: 'arrow', id: uid('arrow'), x, y, width, height, start: { id: from.id }, end: { id: to.id }, ...(text ? { label: { text } } : {}) });
      return [
        title(0, -150, 'Flowchart'),
        start, step, decision, yes, no,
        arrow(start, step, 180, 85, 120, 0),
        arrow(step, decision, 520, 85, 120, 0),
        arrow(decision, yes, 860, 60, 120, -100, 'yes'),
        arrow(decision, no, 860, 110, 120, 100, 'no'),
      ];
    },
  },
  {
    key: 'swot',
    name: 'SWOT',
    description: 'Strengths · Weaknesses · Opportunities · Threats',
    build: () => {
      const cells = [
        ['Strengths', STICKY_COLORS.green, 0, 0],
        ['Weaknesses', STICKY_COLORS.pink, 520, 0],
        ['Opportunities', STICKY_COLORS.blue, 0, 460],
        ['Threats', STICKY_COLORS.yellow, 520, 460],
      ];
      const elements = [title(0, -90, 'SWOT analysis')];
      cells.forEach(([name, color, x, y]) => {
        elements.push(...frame(name, x, y, 480, 420, [sticky(x + 20, y + 30, 'Add a note…', color), sticky(x + 250, y + 30, 'Add a note…', color)]));
      });
      return elements;
    },
  },
];
