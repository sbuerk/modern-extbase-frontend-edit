/* Generated from Build/Sources/TypeScript — do not edit. */
const profileTarget = Object.freeze({ child: null, childUid: null });
function childTarget(child, childUid) {
  return { child, childUid };
}
function newChildTarget(child) {
  return { child, childUid: null };
}
function isChildTarget(target) {
  return target.child !== null;
}
function isNewChildTarget(target) {
  return target.child !== null && target.childUid === null;
}
function targetKey(target) {
  if (target.child === null) {
    return "profile";
  }
  return `${target.child}:${target.childUid ?? "new"}`;
}
function targetsEqual(one, other) {
  return one.child === other.child && one.childUid === other.childUid;
}
function targetScope(target) {
  return target.child ?? "profile";
}
export {
  childTarget,
  isChildTarget,
  isNewChildTarget,
  newChildTarget,
  profileTarget,
  targetKey,
  targetScope,
  targetsEqual
};
