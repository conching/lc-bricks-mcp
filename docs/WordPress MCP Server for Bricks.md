# **Architecting the Bridge: A Comprehensive Technical Specification for Integrating Model Context Protocol (MCP) with WordPress and Bricks Builder**

## **Executive Summary**

The rapid evolution of Large Language Models (LLMs) has transitioned the field of artificial intelligence from passive text generation to active system orchestration. Central to this paradigm shift is the **Model Context Protocol (MCP)**, an open standard designed to standardize the exchange of context and execution of commands between AI "hosts" (such as Claude Code or Claude Desktop) and external "servers" (data repositories, development environments, and business tools).

This report presents a definitive, deep-dive architectural analysis and implementation guide for engineering a WordPress plugin that functions as a compliant MCP server, specifically tailored to control the **Bricks Builder** theme engine. The objective is to enable an AI agent to programmatically understand, manipulate, and generate complex Bricks web layouts, effectively turning natural language prompts into visual web structures.

This integration requires navigating a complex intersection of technologies: the stateless, request-driven nature of PHP; the session-oriented, stateful requirements of MCP; and the proprietary, serialized data structures of the Bricks Builder. This document provides senior engineers and systems architects with the theoretical foundation, reverse-engineered specifications, and security protocols necessary to build this bridge. It moves beyond simple API wrapping to discuss the "agentic" implications of exposing a visual page builder to an automated intelligence.

## ---

**1\. The Convergence of Generative AI and CMS Architecture**

### **1.1 The Shift from Copilots to Agents**

The integration of AI into software development has historically functioned on a "Copilot" model—assistants that suggest code snippets within an Integrated Development Environment (IDE). However, the industry is migrating toward "Agents"—autonomous entities capable of perceiving an environment, planning a sequence of actions, and executing them to achieve a high-level goal.1

In the context of WordPress, a Copilot might suggest a PHP function to register a custom post type. An Agent, empowered by MCP, receives the instruction "Build a landing page for a SaaS product," and proceeds to:

1. Query the site to understand the active theme (Bricks).  
2. Analyze existing global styles to ensure consistency.  
3. Construct the data structure for a hero section, feature grid, and pricing table.  
4. Inject this structure directly into the WordPress database.  
5. Validate the output against the Bricks element schema.

This capability relies entirely on the **Model Context Protocol (MCP)**, which provides the standardized "socket" (analogous to a USB-C port for AI) that makes these capabilities discoverable.3

### **1.2 The Strategic Role of WordPress in the MCP Ecosystem**

WordPress powers over 40% of the web, making it the single largest repository of structured content and design logic. Yet, its architecture—rooted in PHP and MySQL—predates the modern, event-driven architectures typically favored by AI researchers. Bridging this gap is not merely a technical exercise but a strategic necessity to future-proof the CMS.4

The implementation of an MCP server within WordPress transforms the CMS from a passive content store into an active participant in the AI workflow. By exposing the "Abilities" of WordPress (publishing, user management, and crucially, design manipulation via Bricks) as MCP "Tools," we allow external intelligence to operate the CMS with the same granularity as a human administrator.4

### **1.3 Why Bricks Builder?**

Bricks Builder represents a unique target for MCP integration due to its developer-centric architecture. Unlike other page builders that heavily rely on shortcodes or convoluted HTML storage, Bricks utilizes a structured, serialized PHP array format stored in post meta.5 This structured data approach aligns perfectly with the JSON-centric nature of LLMs. An AI agent can manipulate a JSON object representing a page layout far more reliably than it can generate raw HTML strings, provided the translation layer between the MCP JSON format and the Bricks PHP serialization format is robust.

## ---

**2\. The Model Context Protocol (MCP) Specification Deep Dive**

To architect a compliant server, one must first possess a nuanced understanding of the MCP specification itself. The protocol defines the rules of engagement between the Host (Claude) and the Server (WordPress).

### **2.1 Protocol Primitives: The Grammar of Context**

MCP is built upon JSON-RPC 2.0. It abstracts interactions into three primary primitives, each serving a distinct role in the WordPress/Bricks context.7

#### **2.1.1 Resources: Reading the Environment**

**Resources** represent passive, read-only data that the server exposes to the client. They are akin to files that the AI can read to gain context.

* **Theoretical Application:** In a Bricks context, resources allow the AI to "see" the current state of the site without executing a tool.  
* **Implementation:** A resource URI scheme bricks://template/{template\_id} could map to the JSON representation of a Bricks template.  
* **Subscription Model:** Crucially, MCP supports subscriptions. If a human designer updates the "Header" template in Bricks, the MCP server can push a notification to the AI, ensuring its context window remains synchronized with the live site state. This prevents the "stale context" hallucination problem common in LLM interactions.7

#### **2.1.2 Tools: Executing Changes**

**Tools** are executable functions that allow the AI to perform actions. They are the primary mechanism for the Bricks integration.

* **Structure:** A tool definition includes a name, description, and a strictly typed inputSchema (JSON Schema Draft 2020-12).  
* **Validation:** The host (Claude) uses the schema to validate its own generated arguments before sending the request. The server then executes the logic.  
* **Bricks Specifics:** Tools will handle complex operations like create\_page\_structure, update\_global\_style, or get\_element\_schema. The inputSchema is vital here; it must tell the AI exactly which properties (e.g., \_width, \_margin) are valid for a given element.8

#### **2.1.3 Prompts: Guided Workflows**

**Prompts** are pre-defined templates that help users accomplish specific tasks. They function as "macros" for the AI conversation.

* **Usage:** A prompt named generate\_landing\_page might accept an argument niche. When selected, the MCP server dynamically constructs a system message containing the site's color palette, active font settings, and a list of available custom elements, instructing the AI to generate a layout adhering to these constraints.  
* **Benefit:** This offloads the "context stuffing" from the user to the server. The user doesn't need to copy-paste their brand guidelines; the MCP Prompt injects them automatically.7

### **2.2 Transport Mechanisms: Stdio vs. HTTP**

The MCP specification is transport-agnostic but defines two standard implementations: **Stdio** and **Streamable HTTP (SSE)**. The choice of transport fundamentally dictates the architecture of the WordPress plugin.9

| Feature | Stdio Transport | Streamable HTTP (SSE) Transport |
| :---- | :---- | :---- |
| **Communication Channel** | Standard Input/Output (stdin/stdout) of a process. | HTTP POST (client \-\> server) and Server-Sent Events (server \-\> client). |
| **State Management** | Persistent process. State can be held in memory variables. | Transient request/response. State must be persisted in DB/Cache. |
| **Latency** | Extremely low (local pipe). | Variable (network latency). |
| **Authentication** | Implicit (runs as local user). | Explicit (Requires App Passwords/OAuth). |
| **WordPress Context** | Executed via WP-CLI (wp mcp start). | Executed via Web Server (Apache/Nginx/Litespeed). |
| **Ideal Use Case** | Local Development (Claude Desktop). | Remote Management (Claude Code connecting to Staging/Prod). |

**Architectural Decision:**

A robust WordPress MCP plugin must support **both**.

* **Local Development:** Developers running local environments (LocalWP, DDEV) need the speed of Stdio via WP-CLI.  
* **Remote Management:** Agents like Claude Code running in the cloud cannot pipe into a local process; they require a public HTTP endpoint exposing the SSE stream.4

## ---

**3\. WordPress as an Application Platform for MCP**

Building a persistent or long-lived server on top of WordPress requires overcoming the "Shared Nothing" architecture of PHP. PHP applications typically tear down the entire memory stack after every HTTP request. MCP, however, expects a continuous session.

### **3.1 The Stdio Implementation via WP-CLI**

For the Stdio transport, we leverage **WP-CLI**, the command-line interface for WordPress.

* **Mechanism:** We define a custom WP-CLI command (e.g., wp mcp start). This command bootstraps the WordPress environment (wp-load.php) and then enters a while(true) loop.  
* **Data Flow:**  
  1. The loop reads from php://stdin.  
  2. When a JSON-RPC message arrives, it is parsed.  
  3. The corresponding Tool or Resource logic is executed using WordPress internal APIs (WP\_Query, get\_post\_meta).  
  4. The result is JSON-encoded and written to php://stdout.  
* **Advantages:** Since the process never terminates, we avoid the overhead of bootstrapping WordPress for every message. This provides a snappy, responsive experience for the AI.4

### **3.2 The HTTP Implementation via Server-Sent Events (SSE)**

Implementing the Streamable HTTP transport is significantly more complex due to PHP's request lifecycle.

* **The Endpoint:** We must register a custom REST API route (e.g., /wp-json/mcp/v1/sse).12  
* **Headers:** To establish an SSE stream, the endpoint must send specific headers immediately:  
  HTTP  
  Content-Type: text/event-stream  
  Cache-Control: no-cache  
  Connection: keep-alive  
  X-Accel-Buffering: no

  The X-Accel-Buffering: no header is critical for Nginx servers, which otherwise buffer the output and break the stream.14  
* **The Loop:** The PHP script enters a loop, checking for new messages (perhaps stored in a transient or database queue) and echoing them in the data: {...}\\n\\n format.  
* **Timeouts:** PHP scripts have a max\_execution\_time. The implementation must handle this gracefully, perhaps by closing the connection before the timeout and expecting the client (Claude) to reconnect (auto-reconnect is a feature of SSE clients).16  
* **Session State:** Since the PHP process might die and restart, "session" data (like the list of active subscriptions) cannot be stored in RAM. It must be stored in the database (e.g., wp\_options or a custom table) keyed by a unique sessionId passed during the handshake.17

## ---

**4\. Reverse-Engineering Bricks Builder**

To enable an AI to build with Bricks, we must understand the proprietary data structures Bricks uses. The AI cannot simply "write HTML"; it must generate the specific data format that Bricks expects to find in the database.

### **4.1 The Data Model: \_bricks\_page\_content\_2**

Bricks does not store its layout data in the standard WordPress post\_content column. Instead, it utilizes a custom post meta key: \_bricks\_page\_content\_2.5

**Format:**

The data is stored as a **serialized PHP array**. This is a critical detail. Serialization is a PHP-specific mechanism (serialize()/unserialize()). Most non-PHP languages (and thus generic AI training data) are more familiar with JSON.

* **Implication:** The MCP Server must act as a translator. It must expose the data to the AI as **JSON**, but internally convert that JSON back into a **PHP Array** and then **serialize** it before saving to the database.

**Structure of the Array:**

The array represents a tree of elements. Each element is an associative array containing:

* id: A unique 6-character string (e.g., brx-abc123).  
* name: The element tag (e.g., section, container, heading, text-basic).  
* parent: The id of the parent element (or 0 for root elements).  
* children: An array of child ids.  
* settings: An associative array of style and content settings (e.g., {'text': 'Hello World', '\_background\_color': '\#ff0000'}).

**Deep Insight:** Bricks uses a "dual linkage" system in some versions—storing both the parent reference in the child AND a children list in the parent. The MCP tool must maintain this integrity. If the AI adds a child but fails to update the parent's children array, the element might exist in the database but fail to render in the builder.18

### **4.2 The Element Registry: Bricks\\Elements**

To allow the AI to know *what* it can build, we need to query the Bricks element registry.

* **The Class:** \\Bricks\\Elements is the core singleton managing elements.  
* **The Property:** \\Bricks\\Elements::$elements is a public static array containing all registered elements.19  
* **The Initialization Problem:** Bricks initializes its elements on the WordPress init hook with priority 10\.  
* **The Solution:** If our MCP server tries to read this array too early (e.g., on plugins\_loaded), it will be empty. We must hook our logic into init with a priority *higher* than 10\. The best practice is to use PHP\_INT\_MAX to ensure we run after Bricks and all third-party addons have registered their elements.19

**Code Logic for Discovery:**

PHP

add\_action( 'init', function() {  
    if ( class\_exists( '\\Bricks\\Elements' ) ) {  
        $all\_elements \= \\Bricks\\Elements::$elements;  
        // Now we can convert this array into a JSON Schema for the AI  
    }  
}, PHP\_INT\_MAX );

### **4.3 Element Controls and Schema**

Each element in Bricks defines "Controls" (inputs in the editor sidebar). For the AI to successfully configure an element, it must know the keys for these controls.

* **Retrieval:** The get\_controls() method on an element class returns the configuration array.  
* **Complexity:** These arrays contain UI logic (tabs, groups, instructions). The MCP Server must **sanitize** this data, stripping out UI metadata and leaving only the *functional* schema (key name, data type, allowed options) to reduce the token count sent to the AI.20

## ---

**5\. Architectural Blueprint: The Bricks-MCP-Server Plugin**

This section details the concrete implementation of the plugin. We will name the plugin bricks-mcp-server.

### **5.1 Directory Structure**

A professional plugin structure is required to maintain separation of concerns, especially when handling different transport layers.

bricks-mcp-server/

├── composer.json \# Dependency management

├── bricks-mcp-server.php \# Main entry point

├── src/

│ ├── Server/

│ │ ├── McpServer.php \# Core server logic, handles JSON-RPC

│ │ ├── Transport/

│ │ │ ├── TransportInterface.php

│ │ │ ├── StdioTransport.php \# WP-CLI implementation

│ │ │ └── HttpTransport.php \# SSE implementation

│ ├── Bricks/

│ │ ├── ElementRegistry.php \# Wrapper for Bricks\\Elements

│ │ ├── PageBuilder.php \# Handles CRUD for \_bricks\_page\_content\_2

│ │ ├── SchemaGenerator.php \# Converts Bricks controls to JSON Schema

│ │ └── Serializer.php \# Handles JSON \<-\> PHP Array conversion

│ ├── Tools/

│ │ ├── ToolInterface.php

│ │ ├── CreatePage.php

│ │ ├── GetElementSchema.php

│ │ └── ListTemplates.php

│ └── Utils/

│ ├── Security.php \# App Password & Capability checks

│ └── Logger.php \# Debugging support

└── vendor/ \# Composer dependencies (php-mcp-sdk)

### **5.2 Dependency Management**

The plugin should rely on the official modelcontextprotocol/php-sdk (if available) or a compliant community implementation.

* **Composer:** Use composer require to pull in the SDK.  
* **Autoloading:** The plugin must include require\_once \_\_DIR\_\_. '/vendor/autoload.php'; to load the classes.13

### **5.3 Initialization and Bootstrapping**

The bricks-mcp-server.php file must determine the context and initialize the correct transport.

PHP

/\*\*  
 \* Plugin Name: Bricks MCP Server  
 \* Description: Connects AI Agents to Bricks Builder via Model Context Protocol.  
 \*/

use BricksMcp\\Server\\McpServer;  
use BricksMcp\\Server\\Transport\\StdioTransport;  
use BricksMcp\\Server\\Transport\\HttpTransport;

// 1\. WP-CLI Context (Stdio)  
if ( defined( 'WP\_CLI' ) && WP\_CLI ) {  
    WP\_CLI::add\_command( 'mcp start', function() {  
        $server \= new McpServer( new StdioTransport() );  
        $server\-\>run();  
    });  
}

// 2\. HTTP Context (SSE)  
add\_action( 'rest\_api\_init', function() {  
    register\_rest\_route( 'mcp/v1', '/sse',);  
      
    register\_rest\_route( 'mcp/v1', '/message',);  
});

## ---

**6\. The Transport Layer: Implementing Streamable HTTP and SSE in PHP**

The HTTP transport layer is the most fragile component due to PHP's timeout limitations. This section details the robust implementation required for a production environment.

### **6.1 Server-Sent Events (SSE) Logic**

The HttpTransport-\>handle\_sse() method requires specific handling to bypass PHP's output buffering.

**Detailed Implementation:**

1. **Headers:**  
   PHP  
   header( 'Content-Type: text/event-stream' );  
   header( 'Cache-Control: no-cache' );  
   header( 'Connection: keep-alive' );  
   header( 'X-Accel-Buffering: no' ); // Critical for Nginx

2. **Handshake:** Send the initial endpoint event to tell the client where to POST messages.  
   PHP  
   echo "event: endpoint\\n";  
   echo "data: ". json\_encode(\['uri' \=\> get\_rest\_url(null, 'mcp/v1/message')\]). "\\n\\n";  
   flush(); // Force output to client

3. **The Event Loop:**  
   PHP  
   $start\_time \= time();  
   $timeout \= 25; // Safety margin below max\_execution\_time (usually 30s)

   while ( true ) {  
       if ( time() \- $start\_time \> $timeout ) {  
           break; // Exit gracefully to allow client reconnection  
       }

       // Check for pending messages (e.g., from database or object cache)  
       $messages \= $this\-\>get\_pending\_messages(); 

       foreach ( $messages as $msg ) {  
           echo "event: message\\n";  
           echo "data: ". json\_encode( $msg ). "\\n\\n";  
           flush();  
       }

       // Send a keep-alive comment to prevent connection drop  
       echo ": keep-alive\\n\\n";  
       flush();

       sleep( 1 ); // Prevent CPU spinning  
   }

**Insight:** The get\_pending\_messages() function implies we need a storage mechanism. When the MCP Server executes a tool (which happens in a separate POST request), the result shouldn't just be returned in that POST response—it often needs to be emitted as a notification on the SSE stream, especially for long-running tasks. Using WordPress Transients with a short expiration time is an efficient way to pass messages between the POST request process and the SSE loop process.15

## ---

**7\. The Data Layer: Resources and Prompts**

This layer exposes the passive data of the Bricks site.

### **7.1 Defining Resources for Templates**

The AI needs to read existing templates to mimic their style.

* **Resource URI:** bricks://template/{post\_id}  
* **Implementation:**  
  When the server receives a resources/read request for this URI:  
  1. Parse the post\_id.  
  2. Retrieve \_bricks\_page\_content\_2 meta.  
  3. unserialize() the data.  
  4. json\_encode() the data.  
  5. Return it as the resource content.  
* **MIME Type:** application/json

This allows the AI to execute: "Read the Header template at bricks://template/105 and apply its font settings to this new page."

### **7.2 Defining Prompts for Generation**

Prompts help guide the user.

* **Prompt Name:** generate\_landing\_page  
* **Arguments:**  
  * title (String)  
  * style (Enum: "Modern", "Classic", "Brutalist")  
* **Implementation:**  
  When prompts/get is called:  
  1. The server fetches the site's "Global Settings" (Bricks Theme Styles).  
  2. It constructs a prompt message: "You are an expert Bricks Builder architect. The site uses the font 'Inter' and primary color '\#336699'. Create a landing page titled '{title}' using the 'section', 'container', and 'block' elements. Do not use 'div' unless necessary."  
  3. It attaches the bricks://global-settings resource to the prompt context.

## ---

**8\. The Execution Layer: Defining MCP Tools for Bricks**

This is the most critical section. We define the specific tools that allow the AI to manipulate the site.

### **8.1 Tool: get\_element\_schema**

* **Purpose:** Prevents hallucination by providing the ground truth of element settings.  
* **Input:** {"element": "heading"}  
* **Logic:**  
  1. Access \\Bricks\\Elements::$elements\['heading'\].  
  2. Process the controls array.  
  3. Return a simplified JSON schema:  
     JSON  
     {  
       "type": "object",  
       "properties": {  
         "text": { "type": "string", "description": "The heading text" },  
         "tag": { "type": "string", "enum": \["h1", "h2", "h3"\], "default": "h2" }  
       }  
     }

### **8.2 Tool: create\_bricks\_page**

* **Purpose:** Generates a full page layout.  
* **Input Schema:**  
  JSON  
  {  
    "title": "string",  
    "status": "publish",  
    "elements": \[  
      {  
        "name": "section",  
        "settings": { "\_padding": { "top": "100px" } },  
        "children": \[... \]  
      }  
    \]  
  }

* **Execution Logic:**  
  1. **Create Post:** wp\_insert\_post(\['post\_type' \=\> 'page',...\]).  
  2. **Process Elements:**  
     * The MCP input is a nested JSON tree.  
     * The logic must recursively traverse this tree.  
     * **ID Generation:** Assign a unique brx- ID to every node.  
     * **Flattening:** Bricks (often) stores data as a flat array where elements reference parents. The logic must flatten the nested JSON into a list, setting parent IDs correctly during the traversal.  
  3. **Serialization:** serialize($flattened\_array).  
  4. **Save:** update\_post\_meta($post\_id, '\_bricks\_page\_content\_2', $serialized\_data).  
  5. **Lock Mode:** update\_post\_meta($post\_id, '\_bricks\_editor\_mode', 'bricks').  
  6. **Return:** URL of the created page.

**Crucial Insight on IDs:**

Bricks element IDs are 6-character strings. The MCP Server should implement a helper generate\_bricks\_id():

PHP

function generate\_bricks\_id() {  
    return 'brx-'. substr( md5( uniqid() ), 0, 6 );  
}

Failing to use the brx- prefix prevents Bricks from recognizing the elements styling correctly in the CSS generation phase.18

### **8.3 Tool: list\_global\_classes**

* **Purpose:** Design consistency.  
* **Logic:**  
  1. Get option bricks\_global\_classes.  
  2. Return array of { "name": "primary-btn", "id": "..." }.  
* **AI Instruction:** "When styling elements, prefer applying these classes via the \_css\_classes setting instead of setting raw styles.".21

## ---

**9\. Security Engineering and Access Control**

Exposing a "Page Builder" API is equivalent to Remote Code Execution (RCE) if not secured, as Bricks allows executing PHP code via its "Code" element.

### **9.1 Authentication: Application Passwords**

We rely on WordPress **Application Passwords** (RFC 7617 Basic Auth).

* **Why:** It decouples the AI agent from the user's main password and allows for revocation.  
* **Setup:** The user generates a specific password named "Claude MCP" in their WP Profile.  
* **Client Side:** Claude sends Authorization: Basic base64(user:app\_password) with every HTTP request.  
* **Validation:** In the rest\_api\_init callback, we use check\_auth which validates these headers against WordPress core authentication functions.23

### **9.2 Authorization: Capability Mapping**

Every MCP Tool must define a required WordPress capability.

* list\_templates \-\> edit\_posts  
* create\_bricks\_page \-\> publish\_pages AND edit\_theme\_options (since Bricks is tied to theme logic).  
* execute\_php\_code \-\> unfiltered\_html (This tool should ideally be disabled by default).

**Code Implementation:**

PHP

class CreatePageTool implements ToolInterface {  
    public function execute( $args ) {  
        if (\! current\_user\_can( 'publish\_pages' ) ) {  
            throw new McpError( \-32001, "Unauthorized: User cannot publish pages." );  
        }  
        //... execution logic  
    }  
}

### **9.3 Input Sanitization (KSES)**

The AI might try to inject \<script\> tags into text elements.

* **Rule:** All text content in the settings array must be passed through wp\_kses\_post() before serialization.  
* **Exception:** If the user explicitly asks for a "Code Element" and has the unfiltered\_html capability, raw input is allowed. This logic must be explicit in the CreatePage tool.26

### **9.4 Rate Limiting**

To prevent an AI loop from flooding the database with page revisions:

* Implement a transient-based rate limiter.  
* Limit: 10 page creation requests per minute per user.  
* Return standard JSON-RPC error code \-32000 (Server Error) with message "Rate limit exceeded" if triggered.

## ---

**10\. Client Configuration and Workflows**

How does the user actually connect Claude to this system?

### **10.1 Configuring Claude Desktop (Localhost)**

For a local WordPress site (e.g., LocalWP), the user edits claude\_desktop\_config.json:

JSON

{  
  "mcpServers": {  
    "my-local-site": {  
      "command": "wp",  
      "args":  
    }  
  }  
}

**Insight:** The \--path argument is critical. WP-CLI needs to know where the WordPress installation resides to bootstrap correctly.4

### **10.2 Configuring Claude Code (Remote)**

For a remote staging site, the user needs a local proxy because Claude Code currently expects local execution or a specific remote protocol. Since our plugin implements the standardized "Streamable HTTP" transport:

1. **Proxy:** The user runs a generic MCP HTTP Proxy (available in the MCP ecosystem) locally.  
2. **Config:**  
   JSON  
   {  
     "mcpServers": {  
       "remote-wp": {  
         "command": "npx",  
         "args": \[  
           "-y",  
           "@modelcontextprotocol/server-http-client",  
           "--url",  
           "https://staging.mysite.com/wp-json/mcp/v1/sse"  
         \],  
         "env": {  
            "MCP\_AUTH\_TOKEN": "Basic base64credentials..."  
         }  
       }  
     }  
   }

This creates a local "bridge" process that Claude talks to via Stdio, while the bridge talks to WordPress via SSE/POST.28

### **10.3 Debugging Workflow**

When the AI fails to generate a valid layout:

1. **Inspector:** Use the MCP Inspector (npx @modelcontextprotocol/inspector).  
2. **Connect:** Point it to the local WP-CLI command.  
3. **Test:** Manually call get\_element\_schema for container.  
4. **Verify:** Check if the returned schema matches what the AI is trying to send. Often, version updates in Bricks change control names (e.g., \_margin vs margin). The MCP server must be kept up to date or dynamically query the installed Bricks version.23

## ---

**11\. Future-Proofing and Scalability**

### **11.1 The "Abilities API"**

Automattic and the WordPress core team are discussing an "Abilities API" to standardize how plugins expose functionality to AI. Our Bricks-MCP-Server should be designed with a service-layer architecture. The logic that "Creates a Bricks Page" should be a standalone PHP service class, separate from the MCP Transport layer. This allows us to swap the MCP layer for the Abilities API layer in the future without rewriting the Bricks integration logic.4

### **11.2 Performance at Scale**

If managing a multisite network or high-traffic environment:

* **Caching:** Cache the get\_element\_schema output. It only changes when plugins are updated. Use wp\_cache\_set / wp\_cache\_get.  
* **Asynchronous Processing:** For very large page generations, implement an "Async Tool." The tool returns a "Job ID" immediately. The AI then polls a resource bricks://job/{id} to check the status, while WordPress processes the page creation in a background Action Scheduler queue.2

## ---

**Conclusion**

The architecture defined in this report represents a sophisticated fusion of modern AI protocols with legacy CMS structures. By carefully navigating the constraints of PHP via WP-CLI and Server-Sent Events, and by rigorously reverse-engineering the Bricks data model, developers can build a plugin that empowers AI to be a true "Agentic Architect" for WordPress.

This integration moves beyond simple text generation, allowing Claude to reason about layout, design systems, and component hierarchy, ultimately enabling a workflow where a user can say, "Build me a dark-mode portfolio site with a masonry gallery," and watch as the Bricks structure is instantiated, validated, and published in real-time. This is the future of web development—not replacing the builder, but giving the builder a voice.

### ---

**Appendix: Comparison of Data Structures**

**Table 1: Standard MCP JSON vs. Bricks Internal Storage**

| Feature | MCP JSON (AI View) | Bricks Internal (Database View) | Implication |
| :---- | :---- | :---- | :---- |
| **Element Container** | Nested Object Tree | Flat Array (mostly) | Flattening algorithm required in create\_page tool. |
| **Settings Format** | Typed JSON ({"width": 100}) | String/Array Mixed | Type casting required based on control schema. |
| **Empty Values** | null or omitted | Empty string "" | Server must clean empty values to avoid DB bloat. |
| **Global Classes** | Array of strings | Array of IDs | AI must lookup Class IDs from names before saving. |

### **Appendix: References and Citations**

* **Protocol Specification:**.7  
* **Transport Layer:**.4  
* **Bricks Internals:**.5  
* **Security:**.23  
* **Client Config:**.28  
* **Future Outlook:**.4

#### **Works cited**

1. Model Context Protocol (MCP). MCP is an open protocol that… | by Aserdargun | Nov, 2025, accessed January 13, 2026, [https://medium.com/@aserdargun/model-context-protocol-mcp-e453b47cf254](https://medium.com/@aserdargun/model-context-protocol-mcp-e453b47cf254)  
2. MCP server: A step-by-step guide to building from scratch \- Composio, accessed January 13, 2026, [https://composio.dev/blog/mcp-server-step-by-step-guide-to-building-from-scrtch](https://composio.dev/blog/mcp-server-step-by-step-guide-to-building-from-scrtch)  
3. Model Context Protocol, accessed January 13, 2026, [https://modelcontextprotocol.io/](https://modelcontextprotocol.io/)  
4. MCP Adapter – WordPress AI, accessed January 13, 2026, [https://make.wordpress.org/ai/2025/07/17/mcp-adapter/](https://make.wordpress.org/ai/2025/07/17/mcp-adapter/)  
5. Bricks vs Elementor: The Brutal Truth About Performance & Bloat \- PixelNet, accessed January 13, 2026, [https://www.pixelnet.in/blog/reviews/bricks-vs-elementor/](https://www.pixelnet.in/blog/reviews/bricks-vs-elementor/)  
6. Generate auto descriptions based on Bricks pages · Issue \#677 · sybrew/the-seo-framework, accessed January 13, 2026, [https://github.com/sybrew/the-seo-framework/issues/677](https://github.com/sybrew/the-seo-framework/issues/677)  
7. MCP Docs \- Model Context Protocol （MCP）, accessed January 13, 2026, [https://modelcontextprotocol.info/docs/](https://modelcontextprotocol.info/docs/)  
8. Build Your Own Model Context Protocol Server | by C. L. Beard | BrainScriblr | Nov, 2025, accessed January 13, 2026, [https://medium.com/brainscriblr/build-your-own-model-context-protocol-server-0207625472d0](https://medium.com/brainscriblr/build-your-own-model-context-protocol-server-0207625472d0)  
9. Architecture overview \- Model Context Protocol, accessed January 13, 2026, [https://modelcontextprotocol.io/docs/learn/architecture](https://modelcontextprotocol.io/docs/learn/architecture)  
10. How to implement a Model Context Protocol (MCP) server with SSE? : r/cursor \- Reddit, accessed January 13, 2026, [https://www.reddit.com/r/cursor/comments/1jad0jy/how\_to\_implement\_a\_model\_context\_protocol\_mcp/](https://www.reddit.com/r/cursor/comments/1jad0jy/how_to_implement_a_model_context_protocol_mcp/)  
11. MCP / Streamable HTTP Spec \- Loosely Connected \- WordPress.com, accessed January 13, 2026, [https://looselyconnected.wordpress.com/2025/05/26/mcp-streamable-http-spec/](https://looselyconnected.wordpress.com/2025/05/26/mcp-streamable-http-spec/)  
12. MCP server implementation using the WordPress REST API \- GitHub, accessed January 13, 2026, [https://github.com/mcp-wp/mcp-server](https://github.com/mcp-wp/mcp-server)  
13. modelcontextprotocol/php-sdk: The official PHP SDK for ... \- GitHub, accessed January 13, 2026, [https://github.com/modelcontextprotocol/php-sdk](https://github.com/modelcontextprotocol/php-sdk)  
14. Using server-sent events \- Web APIs | MDN, accessed January 13, 2026, [https://developer.mozilla.org/en-US/docs/Web/API/Server-sent\_events/Using\_server-sent\_events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events)  
15. How to feed a HTML5's EventSource with a REST API custom endpoint?, accessed January 13, 2026, [https://wordpress.stackexchange.com/questions/316615/how-to-feed-a-html5s-eventsource-with-a-rest-api-custom-endpoint](https://wordpress.stackexchange.com/questions/316615/how-to-feed-a-html5s-eventsource-with-a-rest-api-custom-endpoint)  
16. Server Sent Events on Wordpress \- javascript \- Stack Overflow, accessed January 13, 2026, [https://stackoverflow.com/questions/45775135/server-sent-events-on-wordpress](https://stackoverflow.com/questions/45775135/server-sent-events-on-wordpress)  
17. Introducing the Model Context Protocol \- Anthropic, accessed January 13, 2026, [https://www.anthropic.com/news/model-context-protocol](https://www.anthropic.com/news/model-context-protocol)  
18. Create Bricks with content using WP Rest API : r/BricksBuilder \- Reddit, accessed January 13, 2026, [https://www.reddit.com/r/BricksBuilder/comments/1kbcaip/create\_bricks\_with\_content\_using\_wp\_rest\_api/](https://www.reddit.com/r/BricksBuilder/comments/1kbcaip/create_bricks_with_content_using_wp_rest_api/)  
19. Bricks Builder \- Add a custom control to every element \- WP Clevel, accessed January 13, 2026, [https://wpclevel.com/blog/2024/07/bricks-builder-add-custom-control.html](https://wpclevel.com/blog/2024/07/bricks-builder-add-custom-control.html)  
20. Element Controls \- Bricks Academy, accessed January 13, 2026, [https://academy.bricksbuilder.io/article/element-controls/](https://academy.bricksbuilder.io/article/element-controls/)  
21. Extending the API \- Developers \- Bricks Community Forum, accessed January 13, 2026, [https://forum.bricksbuilder.io/t/extending-the-api/2620](https://forum.bricksbuilder.io/t/extending-the-api/2620)  
22. How to programmatically add a class to an element in Bricks \- BricksLabs, accessed January 13, 2026, [https://brickslabs.com/how-to-programmatically-add-a-class-to-an-element-in-bricks/](https://brickslabs.com/how-to-programmatically-add-a-class-to-an-element-in-bricks/)  
23. MCP Server \- WS Form, accessed January 13, 2026, [https://wsform.com/knowledgebase/mcp-server/](https://wsform.com/knowledgebase/mcp-server/)  
24. jpollock/wordpress-mcp \- GitHub, accessed January 13, 2026, [https://github.com/jpollock/wordpress-mcp](https://github.com/jpollock/wordpress-mcp)  
25. Interacting with the WordPress REST API, accessed January 13, 2026, [https://learn.wordpress.org/lesson/interacting-with-the-wordpress-rest-api/](https://learn.wordpress.org/lesson/interacting-with-the-wordpress-rest-api/)  
26. Securing Custom WordPress API Endpoints: 11 Essential Tips \- WP Rocket, accessed January 13, 2026, [https://wp-rocket.me/blog/wordpress-api-endpoints/](https://wp-rocket.me/blog/wordpress-api-endpoints/)  
27. Proven Tips Securing Your WordPress REST API, accessed January 13, 2026, [https://getshieldsecurity.com/blog/wordpress-rest-api-security/](https://getshieldsecurity.com/blog/wordpress-rest-api-security/)  
28. Model Context Protocol (MCP) Integration | WooCommerce developer docs, accessed January 13, 2026, [https://developer.woocommerce.com/docs/features/mcp/](https://developer.woocommerce.com/docs/features/mcp/)  
29. Automattic/wordpress-mcp: WordPress MCP — This repository will be deprecated as stable releases of mcp-adapter become available. Please use https://github.com/WordPress/mcp-adapter for ongoing development and support. \- GitHub, accessed January 13, 2026, [https://github.com/Automattic/wordpress-mcp](https://github.com/Automattic/wordpress-mcp)  
30. Specification and documentation for the Model Context Protocol \- GitHub, accessed January 13, 2026, [https://github.com/modelcontextprotocol/modelcontextprotocol](https://github.com/modelcontextprotocol/modelcontextprotocol)  
31. Specification \- Model Context Protocol, accessed January 13, 2026, [https://modelcontextprotocol.io/specification/draft](https://modelcontextprotocol.io/specification/draft)  
32. How To Use Nestable Elements In Bricks Builder, accessed January 13, 2026, [https://bricksultra.com/how-to-use-nestable-elements-in-bricks-builder/](https://bricksultra.com/how-to-use-nestable-elements-in-bricks-builder/)  
33. How to Secure Your WordPress REST API and Prevent User ID Exposure \- Internet Dzyns, accessed January 13, 2026, [https://idzyns.com/how-to-secure-your-wordpress-rest-api-and-prevent-user-id-exposure/](https://idzyns.com/how-to-secure-your-wordpress-rest-api-and-prevent-user-id-exposure/)