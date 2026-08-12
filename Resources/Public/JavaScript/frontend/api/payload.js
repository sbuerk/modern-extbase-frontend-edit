/* Generated from Build/Sources/TypeScript — do not edit. */
function fieldPayload(profileUid, target, field, value) {
  return { ...recordIdentity(profileUid, target), field, value };
}
function recordPayload(profileUid, target, data) {
  return { ...recordIdentity(profileUid, target), data: { ...data } };
}
function addChildPayload(profileUid, child, data) {
  return { uid: profileUid, child, data: { ...data } };
}
function removeChildPayload(profileUid, child, childUid) {
  return { uid: profileUid, child, childUid };
}
function reorderPayload(profileUid, child, order) {
  return { uid: profileUid, child, order: [...order] };
}
function visibilityPayload(profileUid, child, childUid, hidden) {
  return { uid: profileUid, child, childUid, hidden };
}
const pluginNamespace = "tx_modernextbasefrontendedit_ajax";
const imageUploadPart = `${pluginNamespace}[profile][image]`;
const imageUidPart = `${pluginNamespace}[uid]`;
function imageUploadBody(profileUid, file) {
  const body = new FormData();
  body.append(imageUidPart, String(profileUid));
  body.append(imageUploadPart, file, file.name);
  return body;
}
function removeImagePayload(profileUid) {
  return { uid: profileUid };
}
function recordIdentity(profileUid, target) {
  if (target.child === null) {
    return { uid: profileUid };
  }
  if (target.childUid === null) {
    return { uid: profileUid, child: target.child };
  }
  return { uid: profileUid, child: target.child, childUid: target.childUid };
}
export {
  addChildPayload,
  fieldPayload,
  imageUidPart,
  imageUploadBody,
  imageUploadPart,
  pluginNamespace,
  recordPayload,
  removeChildPayload,
  removeImagePayload,
  reorderPayload,
  visibilityPayload
};
