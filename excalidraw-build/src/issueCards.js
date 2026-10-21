// Board issues shown on a whiteboard: a card linked to the item, coloured by status category.

const CARD = { width: 290, height: 84 };
// Helvetica (system font): cards are data, not sketches — and it renders without a webfont
const FONT_FAMILY_HELVETICA = 2;

const STATUS_COLORS = {
  todo: { background: '#e9ecef', stroke: '#868e96' },
  in_progress: { background: '#d0ebff', stroke: '#1971c2' },
  done: { background: '#d3f9d8', stroke: '#2f9e44' },
};

const truncate = (text, max) => (text.length > max ? `${text.slice(0, max - 1)}…` : text);

/** What the card shows — when it changes, the card is redrawn on refresh. */
function cardText(item) {
  const meta = [item.status, item.assignee?.name ?? 'Unassigned'].filter(Boolean).join(' · ');
  return `${item.key}  ${truncate(item.title, 60)}\n${meta}`;
}

export function isIssueCard(element) {
  return element?.customData?.brainitive === 'issue' && element.customData.itemId != null;
}

/** Element skeleton for convertToExcalidrawElements. */
export function issueCard(x, y, item, id = undefined) {
  const colors = STATUS_COLORS[item.status_category] ?? STATUS_COLORS.todo;
  const text = cardText(item);
  return {
    type: 'rectangle',
    ...(id ? { id } : {}),
    x,
    y,
    width: CARD.width,
    height: CARD.height,
    backgroundColor: colors.background,
    strokeColor: colors.stroke,
    fillStyle: 'solid',
    strokeWidth: 1,
    roughness: 0,
    roundness: { type: 3 },
    link: item.url,
    label: { text, fontSize: 16, fontFamily: FONT_FAMILY_HELVETICA, textAlign: 'left', verticalAlign: 'middle', strokeColor: '#1e1e1e' },
    customData: { brainitive: 'issue', itemId: item.id, signature: `${item.status_category}|${text}` },
  };
}
