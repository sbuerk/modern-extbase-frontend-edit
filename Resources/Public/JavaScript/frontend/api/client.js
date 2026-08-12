/* Generated from Build/Sources/TypeScript — do not edit. */
import { interpretResponse, noResponseStatus } from "@sbuerk/modern-extbase-frontend-edit/frontend/api/response.js";
class ProfileEndpointClient {
  /**
   * Written out rather than as constructor parameter properties, so that this
   * module — like every other module outside `component/` — contains only
   * syntax that can be erased. That is what lets a test import it into a
   * plain node process, which strips the types and runs the result.
   */
  constructor(endpoints, requestToken, fetchImpl = (input, init) => fetch(input, init)) {
    this.endpoints = endpoints;
    this.requestToken = requestToken;
    this.fetchImpl = fetchImpl;
  }
  /**
   * One request, whatever the body is.
   *
   * Both body kinds end in {@see interpretResponse}, and that is a rule rather
   * than an implementation detail: every endpoint answers with the same
   * envelope, so a second way of reading an answer would be a second place for
   * the surface to disagree with the server about what was persisted.
   */
  async send(action, body) {
    let response;
    try {
      response = await this.fetchImpl(this.endpoints[action], {
        method: "POST",
        credentials: "same-origin",
        headers: headersFor(body, this.requestToken),
        body: body instanceof FormData ? body : JSON.stringify(body)
      });
    } catch {
      return { kind: "error", status: noResponseStatus, codes: [] };
    }
    return interpretResponse(response.status, await decode(response));
  }
}
function headersFor(body, requestToken) {
  const headers = {
    Accept: "application/json",
    "X-TYPO3-RequestToken": requestToken
  };
  if (!(body instanceof FormData)) {
    headers["Content-Type"] = "application/json";
  }
  return headers;
}
async function decode(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}
export {
  ProfileEndpointClient
};
