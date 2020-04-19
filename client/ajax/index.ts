import Kefir, { Emitter, Stream } from 'kefir';

export class ObsResponse {
  constructor(private xhr: XMLHttpRequest) {
    this.xhr = xhr;
  }

  json(): Stream<unknown, TypeError> {
    const xhr = this.xhr;

    return Kefir.stream(emitter => {
      let result: unknown;

      // We're doing this to ensure only the parsing is caught
      try {
        result = JSON.parse(xhr.response || xhr.responseText);
      } catch (e) {
        emitter.error(
          new TypeError(`Error parsing JSON response: ${e.message}`),
        );
      } finally {
        if (result) {
          emitter.value(result);
        }
      }

      emitter.end();
    });
  }
}

export type AjaxOptions = {
  method?: string;
  body?: string;
  credentials?: 'include';
  headers?: {
    [key: string]: string;
  };
};

export class AjaxError extends Error {
  body: string;
  status: number;

  constructor(message: string, status: number = 0, body: string = '') {
    super(message);
    this.status = status;
    this.body = body;
  }
}

export const ajax$ = (
  url: string,
  { method = 'GET', headers = {}, credentials, body }: AjaxOptions = {},
): Stream<ObsResponse, TypeError> =>
  Kefir.stream((emitter: Emitter<ObsResponse, AjaxError>) => {
    const xhr = new XMLHttpRequest();

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 400) {
        emitter.value(new ObsResponse(xhr));
      } else {
        emitter.error(
          new AjaxError(
            `${xhr.status} - ${xhr.statusText}`,
            xhr.status,
            xhr.response || xhr.responseText,
          ),
        );
      }
    };

    xhr.ontimeout = xhr.onerror = () =>
      emitter.error(new AjaxError('Network request failed'));

    xhr.open(method, url, true);

    if (credentials === 'include') {
      xhr.withCredentials = true;
    }

    for (const name in headers) {
      xhr.setRequestHeader(name, headers[name]);
    }

    xhr.send(body != null ? body : null);

    return () => xhr.abort();
  })
    .take(1)
    .takeErrors(1);

export type AjaxService = typeof ajax$;
