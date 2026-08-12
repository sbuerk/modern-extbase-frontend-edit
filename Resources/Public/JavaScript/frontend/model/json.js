/* Generated from Build/Sources/TypeScript — do not edit. */
function readJson(raw) {
  if (raw === null || raw.trim() === "") {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}
export {
  readJson
};
