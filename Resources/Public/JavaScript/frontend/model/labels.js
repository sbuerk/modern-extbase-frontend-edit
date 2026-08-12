/* Generated from Build/Sources/TypeScript — do not edit. */
function parseLabels(value) {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return {};
  }
  const labels = {};
  for (const [key, entry] of Object.entries(value)) {
    if (typeof entry === "string" && entry !== "") {
      labels[key] = entry;
    }
  }
  return labels;
}
function label(labels, key) {
  return labels[key] ?? key;
}
function fieldLabelKey(scope, field) {
  return `field.${scope}.${field}`;
}
function choiceLabelKey(scope, field, value) {
  return `choice.${scope}.${field}.${value}`;
}
function actionLabelKey(action) {
  return `action.${action}`;
}
function sectionLabelKey(scope) {
  return `section.${scope}`;
}
function stateLabelKey(state) {
  return `state.${state}`;
}
export {
  actionLabelKey,
  choiceLabelKey,
  fieldLabelKey,
  label,
  parseLabels,
  sectionLabelKey,
  stateLabelKey
};
