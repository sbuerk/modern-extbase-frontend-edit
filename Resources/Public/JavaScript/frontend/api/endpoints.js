/* Generated from Build/Sources/TypeScript — do not edit. */
const endpointActions = [
  "save",
  "saveField",
  "addChild",
  "removeChild",
  "reorderChildren",
  "setChildVisibility",
  "uploadImage",
  "removeImage"
];
function parseEndpoints(value) {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return null;
  }
  const source = value;
  const endpoints = {};
  for (const action of endpointActions) {
    const url = source[action];
    if (typeof url !== "string" || url === "") {
      return null;
    }
    endpoints[action] = url;
  }
  return endpoints;
}
export {
  endpointActions,
  parseEndpoints
};
