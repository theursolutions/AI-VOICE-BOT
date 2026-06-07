/**
 * Reusable AJAX Request Function
 *
 * @param {string} url       - API endpoint
 * @param {string} method    - HTTP method: GET, POST, PUT, DELETE
 * @param {object|FormData} data - Request data (supports JSON or FormData for file upload)
 * @param {function} successCallback - Function to handle success
 * @param {function} errorCallback   - Function to handle error
 */
function sendRequest(url, method = "GET", data = {}, successCallback = null, errorCallback = null) {
    let ajaxOptions = {
        url: url,
        type: method,
        cache: false,
        processData: true,   // for JSON/normal requests
        contentType: "application/x-www-form-urlencoded; charset=UTF-8",
        success: function(response) {
            if (typeof successCallback === "function") {
                successCallback(response);
            }
        },
        error: function(xhr, status, error) {
            if (typeof errorCallback === "function") {
                errorCallback(xhr, status, error);
            } else {
                console.error("AJAX Error:", error, xhr.responseText);
            }
        }
    };

    // Detect FormData for file uploads
    console.log(data instanceof FormData);
    if (data instanceof FormData) {
        ajaxOptions.data = data;
        ajaxOptions.processData = false; // don't process FormData
        ajaxOptions.contentType = false; // let browser set correct headers
    } else {
        ajaxOptions.data = data;
    }

    $.ajax(ajaxOptions);
}