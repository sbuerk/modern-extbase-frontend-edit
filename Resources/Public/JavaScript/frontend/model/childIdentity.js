/* Generated from Build/Sources/TypeScript — do not edit. */
function childIdentity(child, record) {
  const detail = child === "email" ? "email" in record ? record.email : "" : "line1" in record ? record.line1 : "";
  return {
    type: record.type.trim(),
    detail: detail.trim()
  };
}
export {
  childIdentity
};
