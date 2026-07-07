# AgSOAR AI Assistant - Tool Execution System Guide

## Overview

The AgSOAR AI Assistant is an integrated component of the AgSOAR Information Systems platform that can execute system tools on behalf of users. Unlike traditional chatbots that provide information, this assistant is designed to understand user requests and interact with system tools to perform actions.

## System Architecture

### Components

1. **AI Assistant** (`AgsoarSystemPrompt.txt`)
   - Integrated into AgSOAR system (not external AI service)
   - Understands user requests for system actions
   - Can request available tools from the system
   - Executes tools and translates responses to users

2. **AI Integration Service** (`ChatbotService.php`)
   - Uses the ordered Text Fixers configured in Settings > API Settings
   - Handles Gemini, Groq, DeepSeek, Cerebras, Mistral, OpenRouter, and Cloudflare Workers AI behind one provider-neutral service
   - Falls back to each enabled provider-model row from top to bottom
   - Loads system context for each session
   - Maintains conversation history
   - Manages tool execution workflow

3. **Chat Controller** (`AIController.php`)
   - Manages user interactions
   - Maintains conversation history
   - Handles session management
   - Coordinates with system tools

4. **System Tools**
   - AgSOAR system functionality exposed as tools
   - Can be called by AI assistant
   - Return structured responses
   - Handle actual business logic

## Tool Execution Workflow

### User Interaction Flow

```
User Request
    ↓
AI Assistant Understanding
    ↓
Clarification Questions (if needed)
    ↓
Request Available Tools
    ↓
System Returns Tool List
    ↓
AI Selects Appropriate Tool
    ↓
AI Executes Tool with Required Data
    ↓
System Processes Tool Request
    ↓
System Returns Success/Failure Response
    ↓
AI Translates Response to User
    ↓
User Receives Confirmation
```

### Example Workflow

**Step 1: User Initial Request**
```
User: "hey please add leave for juan dela cruz on June 18,2025."
```

**Step 2: AI Clarification**
```
AI: "Sure! What type of leave?"
```

**Step 3: User Provides Details**
```
User: "Force Leave"
```

**Step 4: AI Requests Tools**
```
AI: [Internal request to system] "What tools are available for leave management?"
```

**Step 5: System Provides Tool List**
```
System: [
  {
    "name": "create_leave_request",
    "description": "Create a new leave request",
    "required_fields": ["employee_id", "leave_type", "start_date", "end_date", "reason"]
  },
  ...
]
```

**Step 6: AI Executes Tool**
```
AI: [Tool execution request]
{
  "tool": "create_leave_request",
  "data": {
    "employee_id": "juan_dela_cruz",
    "leave_type": "force_leave",
    "start_date": "2025-06-18",
    "end_date": "2025-06-18",
    "reason": "Force leave as requested"
  }
}
```

**Step 7: System Processes**
```
System: [Processes leave request, validates data, creates record]
```

**Step 8: System Returns Response**
```
System: {
  "success": true,
  "message": "Leave request created successfully",
  "request_id": "LR-2025-001",
  "status": "pending"
}
```

**Step 9: AI Translates to User**
```
AI: "I have successfully added the leave for Juan Dela Cruz on June 18, 2025 for you! The request ID is LR-2025-001 and is currently pending approval."
```

## AI Identity and Behavior

### Core Characteristics
- **Identity**: AgSOAR AI Assistant (integrated system component)
- **Purpose**: Execute system tools to help users
- **Knowledge**: Minimal system context, learns through tool interaction
- **Capability**: Can request, select, and execute system tools

### Scope and Limitations
- ✅ CAN: Execute system tools, translate responses, ask clarifying questions
- ✅ CAN: Request tool lists from system, understand user intent
- ❌ CANNOT: Provide general knowledge, access external information
- ❌ CANNOT: Make up features, bypass system controls
- 🔒 SECURITY: No access to system internals, only tool interfaces

### Communication Style
- Professional and helpful
- Asks clarifying questions when needed
- Confirms actions before execution when appropriate
- Translates technical responses into plain language
- Provides clear feedback on results

## Configuration Files

### 1. Minimal System Context
**Location:** `[AI Documentations]/AGSOAR_USER_SUMMARY.md`

**Purpose:** Minimal context about AgSOAR system

**Content:**
- Basic system identity
- AI assistant role description
- High-level system categories
- Tool usage pattern

**Key Principle:** Minimal information only - AI learns through tool interaction

### 2. AI System Prompt
**Location:** `app/Services/AgsoarSystemPrompt.txt`

**Purpose:** Defines AI identity and tool usage behavior

**Key Sections:**
- Identity (AgSOAR system component)
- Capabilities (tool execution)
- Tool usage workflow (6-step process)
- Communication style guidelines
- Example conversation
- Important guidelines

**Maintenance:** Update when tool interaction patterns change

### 3. AI Service Configuration
**Location:** `app/Services/ChatbotService.php`

**Key Methods:**
- `chat()` - Main entry point and Text Fixer fallback loop
- `conversationMessages()` - Constructs provider-neutral conversation history
- `systemPrompt()` - Loads the AgSOAR system context with a safe fallback
- Provider adapters use the selected Text Fixer row's API key, model, timeout, and endpoint. OpenAI-compatible providers share one adapter while Gemini retains its native request format.

The chatbot does not maintain a separate AI provider configuration. Reordering, disabling, adding, or changing models in the Text Fixers list also changes chatbot routing. Provider details remain server-side.

Selected cost-conscious models are `gpt-oss-120b` on the Cerebras free tier with low reasoning effort, `mistral-small-2603`, free `google/gemma-3-12b-it:free` through OpenRouter, and Cloudflare's multilingual `@cf/zai-org/glm-4.7-flash`. Cloudflare additionally requires an Account ID on its API Settings row.

## Tool System Design

### Tool Structure

Each system tool should have:

```json
{
  "name": "tool_name",
  "description": "What the tool does",
  "required_fields": ["field1", "field2"],
  "optional_fields": ["field3"],
  "returns": {
    "success": boolean,
    "message": string,
    "data": object
  }
}
```

### Tool Discovery

The AI assistant can request available tools:

```
AI Request: "What tools are available for [category]?"
System Response: [List of relevant tools with descriptions]
```

### Tool Execution

The AI executes tools with structured data:

```
AI Request: {
  "tool": "tool_name",
  "data": {
    "required_field": "value",
    "optional_field": "value"
  }
}
System Response: {
  "success": true/false,
  "message": "Human readable result",
  "data": { ... }
}
```

## Session Management

### Session Context
- User authentication maintained
- Conversation history for context
- Tool execution state tracking
- 24-hour session duration

### Conversation Memory
- Last 20 messages maintained
- Provides context for follow-up requests
- Helps AI understand user intent over time

## Security Considerations

### Access Control
- AI requires authenticated user session
- Tool execution respects user permissions
- No system internals exposed to AI
- All actions logged for audit trail

### Data Protection
- No sensitive system knowledge in AI context
- Tool interfaces only (no implementation details)
- User data handled through secure tool calls
- All tool executions logged

### Scope Enforcement
- AI can only execute defined tools
- No direct database or system access
- Cannot bypass system controls
- Limited to tool-based interactions

## Implementation Requirements

### Tool Registration
Tools must be registered with the system to be discoverable by AI:

```php
// Example tool registration
ToolRegistry::register([
    'name' => 'create_leave_request',
    'description' => 'Create a new leave request for an employee',
    'handler' => LeaveRequestHandler::class,
    'required_fields' => ['employee_id', 'leave_type', 'dates'],
    'permissions' => ['create_leave']
]);
```

### Tool Handler Structure
Each tool needs a handler class:

```php
class LeaveRequestHandler implements ToolHandlerInterface
{
    public function execute(array $data): ToolResponse
    {
        // Validate data
        // Execute business logic
        // Return structured response
    }
    
    public function getSchema(): ToolSchema
    {
        // Define tool schema
    }
}
```

### AI-Tool Integration
The system needs to handle:

1. **Tool Discovery Requests**
   - AI requests available tools
   - System returns relevant tools based on context
   - Respect user permissions

2. **Tool Execution Requests**
   - AI calls specific tool with data
   - System validates request and permissions
   - Execute tool and return response

3. **Response Translation**
   - System provides structured response
   - AI translates to user-friendly language
   - Handle errors appropriately

## Testing and Validation

### Test Scenarios

**Basic Tool Execution:**
1. User requests simple action
2. AI identifies appropriate tool
3. Tool executes successfully
4. AI confirms result to user

**Complex Workflow:**
1. User requests complex action
2. AI asks clarifying questions
3. AI requests multiple tools
4. Tools execute in sequence
5. AI provides comprehensive result

**Error Handling:**
1. Tool execution fails
2. AI receives error response
3. AI translates error appropriately
4. User receives helpful error message

**Permission Boundaries:**
1. User requests action beyond permissions
2. AI attempts tool execution
3. System denies due to permissions
4. AI explains limitation to user

## Future Enhancements

### Potential Improvements
- [ ] Multi-tool workflows
- [ ] Transaction support (rollback on failure)
- [ ] Proactive tool suggestions
- [ ] Natural language to tool mapping
- [ ] Tool execution preview
- [ ] Batch tool operations
- [ ] Tool usage analytics

---

**Document Status:** This guide explains the AgSOAR AI Assistant tool execution system. The AI is an integrated system component that executes tools to help users, not a knowledge base providing information.
